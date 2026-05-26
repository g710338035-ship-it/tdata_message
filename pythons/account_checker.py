import os
import asyncio
import hashlib
import phonenumbers
from phonenumbers import geocoder
import glob
import errno
import time
import sys
import contextvars
import socket
# FastAPI / Uvicorn
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
import uvicorn
from starlette.middleware.base import BaseHTTPMiddleware
# 导入新增的代理处理模块
from proxy_handler import parse_proxytul,parse_proxy, test_proxy
# 导入重构后的处理类
from tdata_processor import TelegramAccountHandler


# 导入拆分后的业务逻辑模块
from account_service import (   
    run_tdesktop_decrypter,
    build_telethon_session,
    build_telethon_session_then,
)
import traceback
import multiprocessing
import signal

# 导入日志配置 - 放在最前面
from logging_config import setup_global_logging, get_logger

# 配置全局日志
root_logger = setup_global_logging()
logger = get_logger(__name__)

#logger.disabled = False 
from concurrent.futures import ThreadPoolExecutor
# 全局线程池 - 替代进程池，处理I/O密集型任务
THREAD_POOL = ThreadPoolExecutor(max_workers=15)  # 根据实际调整

# 全局缓存和进程间通信队列
ACCOUNT_HANDLER_CACHE = {}
GLOBAL_CACHE_LOCK = asyncio.Lock() 

from concurrent.futures import ProcessPoolExecutor
# 全局进程池 - 复用进程减少开销（根据CPU核心数调整）
PROCESS_POOL = ProcessPoolExecutor(max_workers=8)

_CURRENT_PORT = contextvars.ContextVar("current_port", default=None)
_TASK_SEMAPHORES_BY_PORT = {}
_INIT_SEMAPHORES_BY_PORT = {}


def _get_current_port() -> int:
    port = _CURRENT_PORT.get()
    if isinstance(port, int) and port > 0:
        return port
    if isinstance(SERVER_PORT, int) and SERVER_PORT > 0:
        return SERVER_PORT
    return 0


def get_task_semaphore() -> asyncio.Semaphore:
    port = _get_current_port()
    sem = _TASK_SEMAPHORES_BY_PORT.get(port)
    if sem is None:
        per_port = int(os.getenv("TASK_CONCURRENCY_PER_PORT", os.getenv("TASK_CONCURRENCY", "20")))
        sem = asyncio.Semaphore(per_port)
        _TASK_SEMAPHORES_BY_PORT[port] = sem
    return sem


def get_init_semaphore() -> asyncio.Semaphore:
    port = _get_current_port()
    sem = _INIT_SEMAPHORES_BY_PORT.get(port)
    if sem is None:
        per_port = int(os.getenv("INIT_CONCURRENCY_PER_PORT", os.getenv("INIT_CONCURRENCY", "100")))
        sem = asyncio.Semaphore(per_port)
        _INIT_SEMAPHORES_BY_PORT[port] = sem
    return sem

GLOBAL_API_ID = 2040
GLOBAL_API_HASH = 'b18441a1ff607e10a989891a5462e627'

from mapdc_cache import init_global_dc_cache
       
# -------------------------- 节点管理核心配置 --------------------------
NODE_CACHE = {}  # 节点缓存 {node_key: {ip, port, load, ...}}
ACCOUNT_NODE_BIND = {}  # 账号-节点绑定 {account_key: node_key}
NODE_HEARTBEAT_TIMEOUT = 30  # 节点心跳超时（秒）
NODE_MAX_LOAD = 50  # 节点最大负载
NODE_LOCK = asyncio.Lock()
BIND_LOCK = asyncio.Lock()
NODE_CLEANUP_TASK = None
NODE_LOCAL_HEARTBEAT_TASK = None
SERVER_PORT = None
LOCAL_NODE_KEYS = set()
ACCOUNT_ACTION_LOCKS = {}
ACCOUNT_ACTION_LOCKS_LOCK = asyncio.Lock()
# 定义全局变量
global_db = None
global_cache = None

def get_local_ip():
    """获取本机IP"""
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        return s.getsockname()[0]
    except:
        return "127.0.0.1"

LOCAL_IP = get_local_ip()
#LOCAL_NODE_KEY = f"{LOCAL_IP}:{DEFAULT_PORT}"  # 默认本地节点


def _is_truthy(value) -> bool:
    if value is True:
        return True
    if value in (False, None):
        return False
    if isinstance(value, int):
        return value == 1
    if isinstance(value, str):
        return value.strip().lower() in {"1", "true", "yes", "y", "on"}
    return False

# -------------------------- 节点管理工具函数 --------------------------
async def register_node(node_key: str, weight: int = 1):
    """注册节点"""
    async with NODE_LOCK:
        if node_key not in NODE_CACHE:
            ip, port = node_key.split(":")
            NODE_CACHE[node_key] = {
                "ip": ip,
                "port": int(port),
                "weight": weight,
                "load": 0,
                "last_heartbeat": time.time(),
                "status": "online"
            }
            logger.info(f"节点注册成功：{node_key}")
        else:
            NODE_CACHE[node_key]["status"] = "online"
            NODE_CACHE[node_key]["last_heartbeat"] = time.time()
    return True


async def heartbeat_node(node_key: str):
    """刷新节点心跳"""
    if not node_key:
        return False
    async with NODE_LOCK:
        node = NODE_CACHE.get(node_key)
        if not node:
            return False
        node["status"] = "online"
        node["last_heartbeat"] = time.time()
    return True


async def unregister_node(node_key: str):
    """注销节点"""
    if not node_key:
        return False

    async with NODE_LOCK:
        existed = node_key in NODE_CACHE
        if existed:
            NODE_CACHE.pop(node_key, None)

    async with BIND_LOCK:
        if ACCOUNT_NODE_BIND:
            to_delete = [k for k, v in ACCOUNT_NODE_BIND.items() if v == node_key]
            for k in to_delete:
                ACCOUNT_NODE_BIND.pop(k, None)

    if existed:
        logger.info(f"节点已注销：{node_key}")
    return existed


async def _node_health_loop():
    while True:
        await asyncio.sleep(max(1, NODE_HEARTBEAT_TIMEOUT // 2))
        now = time.time()
        offline_nodes = []
        async with NODE_LOCK:
            for node_key, node in NODE_CACHE.items():
                last = node.get("last_heartbeat")
                if not last:
                    continue
                if node.get("status") == "online" and now - last > NODE_HEARTBEAT_TIMEOUT:
                    node["status"] = "offline"
                    offline_nodes.append(node_key)

        for node_key in offline_nodes:
            logger.warning(f"节点心跳超时，标记离线：{node_key}")


async def _local_node_heartbeat_loop():
    while True:
        await asyncio.sleep(max(1, NODE_HEARTBEAT_TIMEOUT // 3))
        if not LOCAL_NODE_KEYS:
            continue
        for node_key in list(LOCAL_NODE_KEYS):
            ok = await heartbeat_node(node_key)
            if not ok:
                await register_node(node_key)


async def _get_account_action_lock(account_key: str) -> asyncio.Lock:
    async with ACCOUNT_ACTION_LOCKS_LOCK:
        lock = ACCOUNT_ACTION_LOCKS.get(account_key)
        if lock is None:
            lock = asyncio.Lock()
            ACCOUNT_ACTION_LOCKS[account_key] = lock
        return lock
        
# 使用线程锁保护全局缓存读写
async def get_account_handler(tdata_path, api_id, api_hash, proxy_str=None):
    # 生成唯一key（逻辑不变）
    normalized_path = os.path.normpath(tdata_path)
    absolute_path = os.path.abspath(normalized_path)
    key = hashlib.md5(absolute_path.encode()).hexdigest()
 
    
    async with GLOBAL_CACHE_LOCK:
        current_pid = os.getpid()
        current_port = _get_current_port()
        if key in ACCOUNT_HANDLER_CACHE:
            logger.info(f"[get_account_handler] [PID:{current_pid}|Port:{current_port}] 【{tdata_path}】 复用已有 handler: key={key}")
            handler = ACCOUNT_HANDLER_CACHE[key]
            # 更新代理配置（如果发生变化）
            if handler.proxy_str != proxy_str:
                handler.proxy_str = proxy_str
                handler.proxy = parse_proxytul(proxy_str) if proxy_str else None
        else:
            logger.info(f"[get_account_handler] [PID:{current_pid}|Port:{current_port}] 【{tdata_path}】新建 handler: key={key}, api_id={api_id}, proxy={proxy_str}")
            # 直接创建实例，而非协程
            ACCOUNT_HANDLER_CACHE[key] = TelegramAccountHandler(
                tdata_path=tdata_path,
                api_id=api_id,
                api_hash=api_hash,
                proxy_str=proxy_str,
            )
            async with get_init_semaphore():
                await ACCOUNT_HANDLER_CACHE[key].initialize()
                if os.getenv("AUTO_ONLINE_ON_CREATE", "0").strip() in {"1", "true", "yes"}:
                    try:
                        await ACCOUNT_HANDLER_CACHE[key].set_online(start_monitor=True)
                    except Exception as e:
                        logger.warning(f"[get_account_handler] 预上线失败: {e}")
    
    handler = ACCOUNT_HANDLER_CACHE[key]
    # 初始化数据库和缓存
  
    logger.info(
        f"[get_account_handler] session_cache 状态: client={handler.session_cache['client']}, "
        f"is_connected={handler.session_cache['is_connected']}"
    )
    return handler  # 返回实例，而非协程


# -------------------------- FastAPI 应用 --------------------------
app = FastAPI(title="Telegram Account Checker", default_response_class=JSONResponse)


class _PortContextMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        port = None
        server = request.scope.get("server")
        if server and len(server) >= 2:
            port = server[1]
        token = _CURRENT_PORT.set(port)
        try:
            return await call_next(request)
        finally:
            _CURRENT_PORT.reset(token)


app.add_middleware(_PortContextMiddleware)


@app.on_event("startup")
async def _startup():
    global NODE_CLEANUP_TASK, NODE_LOCAL_HEARTBEAT_TASK

    try:
        await init_global_dc_cache(GLOBAL_API_ID, GLOBAL_API_HASH)
    except Exception as e:
        logger.error(f"[startup] 初始化DC缓存失败: {e}")

    if NODE_CLEANUP_TASK is None or getattr(NODE_CLEANUP_TASK, "done", lambda: True)():
        NODE_CLEANUP_TASK = asyncio.create_task(_node_health_loop())

    if LOCAL_NODE_KEYS:
        for node_key in list(LOCAL_NODE_KEYS):
            await register_node(node_key)

    if LOCAL_NODE_KEYS and (NODE_LOCAL_HEARTBEAT_TASK is None or getattr(NODE_LOCAL_HEARTBEAT_TASK, "done", lambda: True)()):
        NODE_LOCAL_HEARTBEAT_TASK = asyncio.create_task(_local_node_heartbeat_loop())

@app.on_event("shutdown")
async def _shutdown():
    global NODE_CLEANUP_TASK, NODE_LOCAL_HEARTBEAT_TASK

    if NODE_CLEANUP_TASK is not None and not NODE_CLEANUP_TASK.done():
        NODE_CLEANUP_TASK.cancel()
        try:
            await NODE_CLEANUP_TASK
        except Exception:
            pass
    logger.info(f"[shutdown] 正在关闭 handler: ")
    if NODE_LOCAL_HEARTBEAT_TASK is not None and not NODE_LOCAL_HEARTBEAT_TASK.done():
        NODE_LOCAL_HEARTBEAT_TASK.cancel()
        try:
            await NODE_LOCAL_HEARTBEAT_TASK
        except Exception:
            pass
   

@app.post("/node/register")
async def api_register_node(request: Request):
    try:
        data = await request.json()
        node_key = data.get("node_key")
        if not node_key:
            return JSONResponse(content={"status": False, "message": "缺少node_key"}, status_code=400)
        weight = int(data.get("weight", 1))
        await register_node(node_key=node_key, weight=weight)
        return JSONResponse(content={"status": True, "message": "ok", "data": NODE_CACHE.get(node_key)})
    except Exception as e:
        logger.error(f"[/node/register] 错误: {e}\n{traceback.format_exc()}")
        return JSONResponse(content={"status": False, "message": str(e)}, status_code=500)


@app.post("/node/heartbeat")
async def api_node_heartbeat(request: Request):
    try:
        data = await request.json()
        node_key = data.get("node_key")
        if not node_key:
            return JSONResponse(content={"status": False, "message": "缺少node_key"}, status_code=400)
        ok = await heartbeat_node(node_key)
        if not ok:
            await register_node(node_key=node_key)
        return JSONResponse(content={"status": True, "message": "ok", "data": NODE_CACHE.get(node_key)})
    except Exception as e:
        logger.error(f"[/node/heartbeat] 错误: {e}\n{traceback.format_exc()}")
        return JSONResponse(content={"status": False, "message": str(e)}, status_code=500)


@app.post("/node/unregister")
async def api_node_unregister(request: Request):
    try:
        data = await request.json()
        node_key = data.get("node_key")
        if not node_key:
            return JSONResponse(content={"status": False, "message": "缺少node_key"}, status_code=400)
        ok = await unregister_node(node_key)
        return JSONResponse(content={"status": ok, "message": "ok" if ok else "not found"})
    except Exception as e:
        logger.error(f"[/node/unregister] 错误: {e}\n{traceback.format_exc()}")
        return JSONResponse(content={"status": False, "message": str(e)}, status_code=500)


@app.get("/node/list")
async def api_node_list():
    async with NODE_LOCK:
        nodes = dict(NODE_CACHE)
    return JSONResponse(content={"status": True, "message": "ok", "data": nodes})


# （可选）接口认证：防止非法调用，建议设置自定义密钥
#AUTH_KEY = "your_secure_api_key_2024"  # 替换为自己的安全密钥
"""综合操作接口，支持单账号与批量异步处理（队列模式）"""
@app.post("/telegram_action")
async def telegram_action(request: Request):
    try:
        data = await request.json()
        if not data:
            return JSONResponse(content={"status": False, "message": "请求参数为空"}, status_code=400)

        # 是否为批量操作
        is_batch = isinstance(data.get('batch'), list) and len(data.get('batch', [])) > 0

        if is_batch:
            logger.info(f"[is_batch] 开始批量任务，共 {len(data['batch'])} 个")
        
        # 1. 检查路由（Sticky Routing）
        # 批量任务通常不指定prefer_node，或者由外部负载均衡分发
        # 如果需要严格控制，可以在这里检查 data.get('prefer_node')
        
            task_queue = asyncio.Queue()
            batch_results = [None] * len(data['batch'])

            # 放入任务队列
            for idx, task in enumerate(data['batch']):
                if not isinstance(task, dict) or 'action' not in task:
                    batch_results[idx] = {
                        "task_id": idx,
                        "status": False,
                        "message": "无效的任务格式，缺少action字段",
                        "data": {}
                    }
                    continue
                await task_queue.put((idx, task))

            # worker 协程：使用“当前端口”的信号量控制并发（端口即节点）
            async def worker(worker_id: int):
                while True:
                    try:
                        idx, task = task_queue.get_nowait()
                    except asyncio.QueueEmpty:
                        return
                    try:
                        async with get_task_semaphore():
                            logger.info(f"[worker-{worker_id}] 处理任务 {idx}: {task.get('action')}")
                            result = await process_batch_task(
                                task_id=idx,
                                task=task,
                                api_id=GLOBAL_API_ID,
                                api_hash=GLOBAL_API_HASH
                            )
                            batch_results[idx] = result
                    except Exception as e:
                        batch_results[idx] = {
                            "task_id": idx,
                            "status": False,
                            "message": f"执行出错: {e}"
                        }
                        logger.error(f"[worker-{worker_id}] 任务 {idx} 出错: {e}")
                    finally:
                        task_queue.task_done()

            # 启动有限数量 worker
            max_workers = min(int(os.getenv("BATCH_WORKERS", "20")), len(data['batch']))
            workers = [asyncio.create_task(worker(i)) for i in range(max_workers)]

            await task_queue.join()  # 等待所有任务完成
            for w in workers:
                w.cancel()

            return JSONResponse(content={"status": True, "message": "批量任务完成", "data": batch_results})

        else:
            # 单账号操作逻辑
            if 'action' not in data:
                return JSONResponse(content={"status": False, "message": "缺少action"}, status_code=400)

            # --- 核心修改：Sticky Routing 检查 ---
            prefer_node = data.get('prefer_node')
            if prefer_node:
                try:
                    # 格式通常是 "IP:Port" 或 "Port"
                    target_port = int(prefer_node.split(':')[-1]) if ':' in str(prefer_node) else int(prefer_node)
                    current_port = _get_current_port()
                    
                    # 如果当前端口与期望端口不一致
                    if current_port > 0 and target_port > 0 and current_port != target_port:
                        logger.warning(f"[Sticky Routing] 路由不匹配: 期望={target_port}, 当前={current_port}. 拒绝请求.")
                        return JSONResponse(content={
                            "status": False, 
                            "message": "Node mismatch", 
                            "node_mismatch": True,
                            "expected_node": f"{LOCAL_IP}:{target_port}",
                            "actual_node": f"{LOCAL_IP}:{current_port}"
                        }, status_code=421) # 421 Misdirected Request
                except Exception as e:
                    logger.warning(f"[Sticky Routing] 解析 prefer_node 失败: {e}")
            # ------------------------------------

            result = await process_single_action(data)
            
            # 在返回结果中附加当前节点信息，供 PHP 更新路由表
            if isinstance(result, dict):
                current_port = _get_current_port()
                result["api_address"] = f"{LOCAL_IP}:{current_port}"
                
            return JSONResponse(content=result)

    except Exception as e:
        error_detail = traceback.format_exc()
        logger.error(f"[telegram_action] 错误: {e}\n{error_detail}")
        return JSONResponse(content={
            "status": False,
            "message": str(e),
            "error_detail": error_detail
        }, status_code=500)    
        
        
        
"""处理单个批量任务"""
async def process_batch_task(task_id, task, api_id, api_hash):
    mt_id=task.get('user_id')
    
    try:
        action = task['action']
        tdata_path = task.get('tdata_path')
        # 需要tdata_path的操作列表
        tdata_required_actions = [
            "get_account_info", "set_online", "set_offline","update_photo", "update_nickname", "update_username", "update_bio",
            "change_password", "delete_all_contacts", "leave_all_groups","logout_other_sessions", "send_messages", "get_groups", "get_direct_web_login",
            "get_contacts","get_new_messages", "get_history", "count_total_unread","mark_session_as_read", "block_user", "delete_chat_history","get_common_groups", "deleteUser",'get_webhex'
        ]
        
       
        if action in tdata_required_actions:
            if not tdata_path:
                return {
                    "task_id": task_id,
                    "status": False,
                    "message": f"{action}需要指定tdata_path参数",
                    "data": {}
                }
            
            # 检查tdata目录是否存在
            if not os.path.isdir(tdata_path):
                return {
                    "task_id": task_id,
                    "status": False,
                    "message": f"tdata目录不存在: {tdata_path}",
                    "data": {}
                }
           
            session_path = None
            # 检查tdata目录下是否有session文件
            from session_utils import list_temp_session_files, pick_any_session_file, safe_remove_session_file

            session_files = list_temp_session_files(tdata_path)
            has_valid_session = any(os.path.isfile(f) for f in session_files)
            account_info={}
            session_path = None 
            force_new_session = _is_truthy(task.get('force_new_session', False))
            
            if action == 'set_online' :# and force_new_session
                for f in session_files:
                    
                    if safe_remove_session_file(f):
                        logger.info(f"删除残留的 session 文件: {f}")
                    else:
                        logger.warning(f"删除 session 文件失败: {f}")
                    
                    has_valid_session =False
            # 如果没有有效的session文件，则通过process_single_account获取
            if not has_valid_session:
               # logger.info(f"tdata目录 {tdata_path} 中未找到session文件，开始生成...{task}")
                
                # 调用process_single_account生成session文件
                # 注意：这里需要构造process_single_account所需的参数
                process_start_time = time.perf_counter()
                  
                account_info = await process_single_session(
                    tdata_path=tdata_path,
                    tdata_phone=task.get('phone', ''),  # 从任务参数中获取手机号
                    account_id=str(task_id),  # 用任务ID作为临时account_id
                    api_id=api_id,
                    api_hash=api_hash,
                    proxy_str=task.get('proxy'),
                    tguser_id= task.get('tguser_id'),
                    main_dc_id= task.get('main_dc_id'),
                    auth_key_hex= task.get('auth_key_hex'),
                    prefer_ipv6=task.get('prefer_ipv6', False)
                )

                # 计算process_single_session运行时间
                process_end_time = time.perf_counter()
                process_duration = (process_end_time - process_start_time) * 1000  # 转换为毫秒
                logger.info(f"process_single_session 执行完成，耗时: {process_duration:.2f} 毫秒，task_id: {task_id}")
                
                # 检查生成结果
                if account_info["error"]:
                    # 删除生成失败时残留的 session 文件
                    session_files = list_temp_session_files(tdata_path)
                    for f in session_files:
                        if safe_remove_session_file(f):
                            logger.info(f"删除残留的 session 文件: {f}")
                        else:
                            logger.warning(f"删除 session 文件失败: {f}")
                            
                    verification_result = account_info.get("result", {}).get("verification_result", {})
                    account_status = verification_result.get("account_status", "未知状态")
                    account_status_desc = verification_result.get("account_status_desc", "未获取到状态描述")
                            
                    return {
                        "task_id": task_id,
                        'mt_id':mt_id,
                        "status": False,
                        "message": f"生成session文件失败: {account_info['error']}",
                        "data": {
                            'account_status':account_status,
                            'account_status_desc':account_status_desc
                        }
                    }
                
                session_path=account_info['result']['session_path'];
                # 获取 verification_result 字典（避免重复嵌套获取）
                verification_result = account_info.get('result', {}).get('verification_result', {})
                
                # 先赋值 client 到 task['clients']
                task['clients'] = verification_result.get('client')
                
                # 再将 verification_result 中的 client 设为空（避免后续序列化问题）
                if 'client' in verification_result:
                    verification_result['client'] = None  # 或删除该字段：del verification_result['client']
                    
                #logger.info(f"tdata目录 {tdata_path} 成功生成session文件{account_info}")
               
            else:
                # 使用现有 session 文件
                target_session = pick_any_session_file(tdata_path)
                session_path = os.path.realpath(target_session) if target_session else None
                logger.info(f"直接使用已存在的session文件: {session_path}")
        # 为每个任务创建独立的处理器
        handler = await  get_account_handler(
            tdata_path=session_path,
            api_id=api_id,
            api_hash=api_hash,
            proxy_str=task.get('proxy')
        )
        
        execute_start_time = time.perf_counter()
        normalized_path = os.path.normpath(session_path)
        absolute_path = os.path.abspath(normalized_path)
        account_key = hashlib.md5(absolute_path.encode()).hexdigest()
        action_lock = await _get_account_action_lock(account_key)
        async with action_lock:
            result = await execute_action(handler, action, task)
        # 计算execute_action运行时间
        execute_end_time = time.perf_counter()
        execute_duration = (execute_end_time - execute_start_time) * 1000  # 转换为毫秒
        logger.info(f"execute_action (action: {action}) 执行完成，耗时: {execute_duration:.2f} 毫秒，task_id: {task_id}")
        
        if action == 'set_online':
            result["account_info"] = account_info
        # 添加任务ID
        result["task_id"] = task_id
        result["mt_id"] = mt_id
        #logger.info(f"返回信息{result}")
        if not result.get("status"):  # 用 get() 避免 KeyError，兼容 status 不存在的情况
            from session_utils import list_temp_session_files

            session_files = list_temp_session_files(tdata_path)
            
            if not session_files:
                logger.info(f"未找到 {tdata_path} 目录下的残留 session 文件（匹配规则：temp_*.session）")
                # 若无需后续操作，可直接 return 或 pass
                # return  # 根据实际业务逻辑决定是否退出
            else:
                # 3. 遍历删除文件，增加多重容错
                for file_path in session_files:
                    # 二次校验：确保是文件且符合命名规则（防误删目录/非目标文件）
                    if not os.path.isfile(file_path):
                        logger.warning(f"跳过非文件对象: {file_path}（可能是目录或链接）")
                        continue
                    if not os.path.basename(file_path).startswith("temp_") or not file_path.endswith(".session"):
                        logger.warning(f"跳过不符合命名规则的文件: {file_path}（仅删除 temp_*.session）")
                        continue
                    
                    # 4. 执行删除，捕获所有可能异常
                    try:
                        from session_utils import safe_remove_session_file

                        if not safe_remove_session_file(file_path):
                            raise PermissionError("remove failed")
                        # 记录删除成功的详细信息（含文件大小，便于排查）
                        file_size = os.path.getsize(file_path) if os.path.exists(file_path) else 0
                        logger.info(f"成功删除残留 session 文件: {file_path}，文件大小: {file_size} bytes")
                    except FileNotFoundError:
                        logger.warning(f"删除失败: {file_path}（文件已不存在，可能已被其他进程删除）")
                    except PermissionError:
                        logger.error(f"删除失败: {file_path}（权限不足，请检查目录读写权限）")
                    except OSError as e:
                        # 捕获系统级错误（如文件被占用、磁盘满等）
                        logger.error(f"删除失败: {file_path}（系统错误），错误详情: {str(e)}")
                    except Exception as e:
                        # 捕获其他未预期异常，避免循环中断
                        logger.error(f"删除失败: {file_path}（未知错误），错误详情: {str(e)}", exc_info=True)
        return result
        
    except Exception as e:
        return {
            "task_id": task_id,
            'mt_id':mt_id,
            "status": False,
            "message": f"任务执行失败: {str(e)}",
            "data": {"error": traceback.format_exc()}
        }
"""处理单个操作"""
async def process_single_action(data):
    api_id=GLOBAL_API_ID;
    api_hash=GLOBAL_API_HASH;
    action = data['action']
    
    if action == "test_proxy":
        if not data.get('proxy'):
            return {
                "status": False,
                "message": "test_proxy需要指定proxy参数",
                "data": {}
            }
        proxy_info = parse_proxy(data['proxy'])
        return await test_proxy(proxy_info, int(api_id), api_hash)
    else:
        from session_utils import pick_any_session_file

        input_path = data.get('tdata_path')
        if input_path and os.path.isdir(input_path):
            picked = pick_any_session_file(input_path)
            if picked:
                data['tdata_path'] = picked
        else:
            try:
                if input_path and os.path.isfile(input_path) and os.path.basename(input_path).startswith("temp_"):
                    base_dir = os.path.dirname(input_path)
                    picked = pick_any_session_file(base_dir)
                    if picked:
                        data['tdata_path'] = picked
                        #logger.info(f"[process_single_action] 从临时会话切换为稳定会话: {picked}")
            except Exception:
                pass
        handler = await  get_account_handler(
            tdata_path=data.get('tdata_path'),
            api_id=int(api_id),
            api_hash=api_hash,
            proxy_str=data.get('proxy')
        )
        normalized_path = os.path.normpath(data.get('tdata_path'))
        absolute_path = os.path.abspath(normalized_path)
        account_key = hashlib.md5(absolute_path.encode()).hexdigest()
        action_lock = await _get_account_action_lock(account_key)
        async with action_lock:
            return await execute_action(handler, action, data)
"""执行具体操作的通用函数"""
async def execute_action(handler, action, params):
    
    try:
        async with handler._client_conn_lock:
            async with handler._client_io_lock:
                if action == "get_groups":
                    return await handler.get_groups()            
                elif action == "get_contacts":
                    return await handler.get_contacts()  
                elif action == "get_new_messages":
                    if not params.get('target_id'):
                        return {
                            "status": False,
                            "message": "get_new_messages需要指定target_id参数",
                            "data": {}
                        }
                    return await handler.get_new_messages(
                        target_id=params['target_id'],
                        last_msg_id=int(params.get('last_msg_id', 0)),
                        timeout=int(params.get('timeout', 3))
                    )
                    
                elif action == "get_account_info":
                    return await handler.get_account_info()
                    
                elif action == "set_online":
                    clients=params.get('clients')
                    #logger.info(f"clients 信息{clients}")
                    if clients is not None:
                        return await handler.set_online(clients, start_monitor=True)
                    return await handler.set_online(start_monitor=True)
                    
                elif action == "set_offline":
                    result=await handler.set_offline()
                    # 确保彻底关闭资源（DB、Cache等）
                    normalized_path = os.path.normpath(params.get('tdata_path'))
                    absolute_path = os.path.abspath(normalized_path)
                    key = hashlib.md5(absolute_path.encode()).hexdigest()
                    async with GLOBAL_CACHE_LOCK:
                        if key in ACCOUNT_HANDLER_CACHE:
                            del ACCOUNT_HANDLER_CACHE[key]
                            logger.info(f"[process_batch_task] 批量任务中主动移除离线handler: key={key}")
                            
                    return result
                    
                elif action == "change_password":
                    if not all(k in params for k in ['current_password', 'new_password']):
                        return {
                            "status": False,
                            "message": "change_password需要指定current_password和new_password",
                            "data": {}
                        }
                    return await handler.change_password(
                        params['current_password'], 
                        params['new_password']
                    )
                    
                elif action == "update_photo":
                    if not params.get('photo_path'):
                        return {
                            "status": False,
                            "message": "update_photo需要指定photo_path参数",
                            "data": {}
                        }
                    return await handler.update_profile_photo(params['photo_path'])
                    
                elif action == "update_nickname":
                    if not params.get('first_name'):
                        return {
                            "status": False,
                            "message": "update_nickname需要指定first_name参数",
                            "data": {}
                        }
                    return await handler.update_nickname(
                        params['first_name'], 
                        params.get('last_name', '')
                    )
                    
                elif action == "update_username":
                    if not params.get('username'):
                        return {
                            "status": False,
                            "message": "update_username需要指定username参数",
                            "data": {}
                        }
                    return await handler.update_username(params['username'])
                    
                elif action == "update_bio":
                    if not params.get('bio'):
                        return {
                            "status": False,
                            "message": "update_bio需要指定bio参数",
                            "data": {}
                        }
                    return await handler.update_bio(params['bio'])
                    
                elif action == "delete_all_contacts":
                    return await handler.delete_all_contacts()
                    
                elif action == "leave_all_groups":
                    return await handler.leave_all_groups()
                elif action == "get_webhex":
                    return await handler.get_webhex()    
                elif action == "logout_other_sessions":
                    return await handler.logout_other_sessions()
                    
                elif action == "send_messages":
                    if not all(k in params for k in ['group_id', 'message_type']):
                        return {
                            "status": False,
                            "message": "send_messages需要指定group_id和message_type",
                            "data": {}
                        }
                    
                    # 验证消息类型对应的参数
                    if params['message_type'] == "text" and not params.get('message_text'):
                        return {
                            "status": False,
                            "message": "message_type=text时需要指定message_text",
                            "data": {}
                        }
                    if params['message_type'] == "forward" and not params.get('forward_id'):
                        return {
                            "status": False,
                            "message": "message_type=forward时需要指定forward_id",
                            "data": {}
                        }
                    if params['message_type'] in ["image", "voice"] and not params.get('media_paths'):
                        return {
                            "status": False,
                            "message": f"message_type={params['message_type']}时需要指定media_paths",
                            "data": {}
                        }
                    
                    # 处理媒体文件路径
                    media_paths = []
                    if params.get('media_paths'):
                        media_paths = [path.strip() for path in params['media_paths'].split(',')]
                        
                    return await handler.send_messages(
                        group_ids=params['group_id'],
                        message_type=params['message_type'],
                        text=params.get('message_text'),
                        forward_id=params.get('forward_id'),
                        media_paths=media_paths,
                        delay=int(params.get('delay', 1)),
                        feedback_type=params.get('feedback_type'),
                        first_msg_id=int(params.get('first_msg_id', 0)),
                        message_unique_id=params.get('message_unique_id')
                    )
                    
                elif action == "get_history":
                    if not params.get('target_id'):
                        return {
                            "status": False,
                            "message": "get_history需要指定target_id参数",
                            "data": {}
                        }
                    return await handler.get_history(
                        target_id=params['target_id'],
                        limit=int(params.get('limit', 50)),
                        offset=int(params.get('offset', 0))
                    )
                    
                elif action == "count_total_unread":
                    return await handler.count_total_unread()
                    
                elif action == "mark_session_as_read":
                    if not params.get('group_id'):
                        return {
                            "status": False,
                            "message": "mark_session_as_read需要指定group_id参数",
                            "data": {}
                        }
                    return await handler.mark_session_as_read(params['group_id'])
                    
                elif action == "block_user":
                    if not params.get('target_id'):
                        return {
                            "status": False,
                            "message": "block_user需要指定target_id参数",
                            "data": {}
                        }
                    return await handler.block_user(params['target_id'])
                    
                elif action == "delete_chat_history":
                    if not params.get('target_id'):
                        return {
                            "status": False,
                            "message": "delete_chat_history需要指定target_id参数",
                            "data": {}
                        }
                    return await handler.delete_chat_history(params['target_id'])
                    
                elif action == "get_common_groups":
                    if not params.get('target_id'):
                        return {
                            "status": False,
                            "message": "get_common_groups需要指定target_id参数",
                            "data": {}
                        }
                    return await handler.get_common_groups(params['target_id'])
                    
                elif action == "deleteUser":
                    if not params.get('target_id'):
                        return {
                            "status": False,
                            "message": "deleteUser需要指定target_id参数",
                            "data": {}
                        }
                    return await handler.deleteUser(params['target_id'])
               
                elif action == 'get_direct_web_login':
                    result= await handler.get_direct_web_login() 
                  
                    return result    
                else:
                    return {
                        "status": False,
                        "message": f"不支持的操作: {action}",
                        "data": {}
                    }
            
    except Exception as e:
        return {
            "status": False,
            "message": f"执行{action}失败: {str(e)}",
            "data": {"error_detail": traceback.format_exc()}
        }
   
# -------------- 新增：单账号session处理辅助函数（批量接口依赖）--------------

async def process_single_session(tdata_path, tdata_phone, account_id, api_id, api_hash,proxy_str,tguser_id,main_dc_id,auth_key_hex, prefer_ipv6=True):
    """
    单账号处理逻辑：复用原 /check_account 接口核心逻辑，返回标准化结果
    """
    # 初始化单账号结果（与PHP侧期望格式一致）
    single_result = {
        "account_id": account_id,
        "phone": tdata_phone,
        "result": {},
        "error": ""
    }
    
    # 1. 基础参数校验
    if not os.path.isdir(tdata_path):
        single_result["error"] = f"tdata目录不存在：{tdata_path}"
        return single_result
 
    # 3. 解析代理（如果提供）
    proxy = None
    if proxy_str:
        try:
           # logger.info(f"[proxy_str] 信息: proxy_str={proxy_str}")
            proxy = parse_proxytul(proxy_str)
            # 简单验证代理格式
            if not all(k in proxy for k in ['scheme', 'hostname', 'port']):
                raise ValueError("代理格式无效")
            single_result["result"]["proxy_used"] = f"{proxy['scheme']}://{proxy['hostname']}:{proxy['port']}"
        except Exception as e:
            single_result["result"]["proxy_warning"] = f"代理解析失败：{str(e)}，将使用无代理模式"
    # 4. 复用原 /check_account 接口的核心逻辑（会话生成+验证）
    session = None
    
    # TDesktop 初始化失败，尝试解析 tdata（复用原分支逻辑）
    #logger.info(f"[proxy] 信息: proxy={proxy}")
    try:
       
        session = await build_telethon_session_then(
            tdata_path=tdata_path,
            auth_key_hex=auth_key_hex,
            main_dc_id=main_dc_id,
            user_id=tguser_id,
            api_id=api_id,
            api_hash=api_hash,
            phone=tdata_phone,
            proxy=proxy,  # 新增代理支持
            prefer_ipv6=False
        )
        #logger.info(f"[session] 信息: proxy_session={session}")
        if session["success"]:
            single_result["result"] = {
                'session_path':session["session_file"],
                'verification_result':session
            }
               
        else:
            single_result["result"] = {
                'session_path':session["session_file"],
                'verification_result':session
            }
            single_result["error"] = session["error"]        
                
    except Exception as sub_e:
        single_result["error"] = f"TDesktop失败+解析异常：{str(e)} | {str(sub_e)}"
    '''
    except Exception as e:
        # 其他未知异常
        single_result["error"] = f"单账号处理异常：{str(e)}"
    
    # 4. 确保客户端断开连接（避免资源泄漏）
    if "client" in locals() and locals()["client"] and locals()["client"].is_connected():
        await locals()["client"].disconnect()
    '''    
    #logger.info(f"[single_result] 信息: single_result={single_result}")
    return single_result   


        
# -------------- 新增：批量账号解析接口 /batch_check_account --------------
@app.post("/batch_check_account")
async def batch_check_account(request: Request):
    """
    批量账号检查接口：一次处理多个账号，与PHP批量调用逻辑适配
    请求格式：{"accounts": [{"tdata_path": "xxx", "tdata_phone": "xxx", "account_id": "xxx"}, ...]}
    返回格式：[{"account_id": "xxx", "phone": "xxx", "result": {...}, "error": ""}, ...]
    """
    # 1. 初始化批量结果容器
    batch_result = []
    try:
        # 2. 解析请求参数
        request_data = await request.json()
        if not request_data or not isinstance(request_data.get("accounts"), list):
            return JSONResponse(content=[{"account_id": "", "phone": "", "result": {}, "error": "请求格式错误"}], status_code=400)
        
        # 3. 提取批量账号列表
        accounts = request_data["accounts"]
        if len(accounts) == 0:
            return JSONResponse(content=[{"account_id": "", "phone": "", "result": {}, "error": "账号数组为空"}], status_code=400)
        # 使用线程池处理批量任务
        loop = asyncio.get_event_loop()
        tasks = []
        for account in accounts:
            task = loop.run_in_executor(
                THREAD_POOL,
                process_single_account_sync,
                account.get("tdata_path", ""),
                account.get("tdata_phone", ""),
                account.get("account_id", "")
            )
            tasks.append(task)
        
        batch_result = await asyncio.gather(*tasks)
        
    except Exception as e:
        # 全局异常处理：为所有未处理账号标记错误
        error_msg = f"批量处理全局异常：{str(e)}"
        processed_ids = {item["account_id"] for item in batch_result if "account_id" in item}
        for account in accounts:
            account_id = account.get("account_id", "")
            if account_id not in processed_ids:
                batch_result.append({
                    "account_id": account_id,
                    "phone": account.get("tdata_phone", ""),
                    "result": {},
                    "error": error_msg
                })
    
    # 6. 返回批量结果（与PHP侧解析逻辑适配）
    return JSONResponse(content=batch_result, status_code=200)


def process_single_account_sync(tdata_path, tdata_phone, account_id):
    """同步版本的单账号处理函数，用于进程池调用"""
    single_result = {
        "account_id": account_id,
        "phone": tdata_phone,
        "result": {},
        "error": ""
    }
    
    # 1. 基础参数校验
    if not os.path.isdir(tdata_path):
        single_result["error"] = f"tdata目录不存在：{tdata_path}"
        return single_result
   
   
    try:
        decryption_result = run_tdesktop_decrypter(tdata_path)
    except Exception as e:
        logger.error(f"[EXCEPTION] run_tdesktop_decrypter 出错：{e}")
        single_result["error"] = f"tdata解析异常：{e}"
        return single_result
    
    if not decryption_result.get("success"):
        single_result["error"] = f"TDesktop失败+tdata解析失败：{decryption_result.get('error', '')}"
        return single_result
    logger.info(f"[EXCEPTION] decryption_result：{decryption_result}")
    # 构建会话并验证
    user_id = decryption_result["user_id"]
    main_dc_id = decryption_result["main_dc_id"]
    auth_key_hex = decryption_result["auth_key_hex"]
    
    # 解析国家信息（优化异常处理）
    country = "未知"
    if tdata_phone:
        try:
            phone = tdata_phone if tdata_phone.startswith('+') else '+' + tdata_phone
            phone_number = phonenumbers.parse(phone, None)
            country = geocoder.country_name_for_number(phone_number, "zh")
        except phonenumbers.NumberParseException:
            country = "号码格式错误"
        except Exception:
            pass  # 其他异常不影响主流程
    
    single_result["result"] = {
            "status": 1,
            "user_id": user_id,
            "auth_key": auth_key_hex,
            "phone": tdata_phone,
            "country": country,
            "main_dc_id": main_dc_id,
            "account_status": "正常",
            'account_status_desc':'账户解析成功'
        }
    #logger.info(f"[single_result] 信息: single_result={single_result}")    
    return single_result

# -------------------------- 多端口启动 --------------------------

def _uvicorn_limits():
    limit_concurrency = int(os.getenv("UVICORN_LIMIT_CONCURRENCY", "300"))
    limit_max_requests_raw = os.getenv("UVICORN_LIMIT_MAX_REQUESTS", "").strip()
    limit_max_requests = int(limit_max_requests_raw) if limit_max_requests_raw else None
    if limit_max_requests is not None and limit_max_requests <= 0:
        limit_max_requests = None
    timeout_keep_alive = int(os.getenv("UVICORN_TIMEOUT_KEEP_ALIVE", "5"))
    return limit_concurrency, limit_max_requests, timeout_keep_alive


def _make_server(port: int) -> uvicorn.Server:
    limit_concurrency, limit_max_requests, timeout_keep_alive = _uvicorn_limits()
    config = uvicorn.Config(
        app,
        host="0.0.0.0",
        port=port,
        workers=1,
        loop="asyncio",
        limit_concurrency=limit_concurrency,
        limit_max_requests=limit_max_requests,
        timeout_keep_alive=timeout_keep_alive,
        access_log=False,
        #lifespan="on",
    )
    server = uvicorn.Server(config)
    server.install_signal_handlers = lambda: None
    return server
    
async def cleanup_handlers():
    logger.info(f"[cleanup] 开始清理 handlers...")
    async with GLOBAL_CACHE_LOCK:
        logger.info(f"[cleanup] 已获取锁，当前缓存大小: {len(ACCOUNT_HANDLER_CACHE)}")
        for key, handler in list(ACCOUNT_HANDLER_CACHE.items()):
            try:
                logger.info(f"[cleanup] 正在关闭 handler: {key}")
                await handler.set_offline()
            except Exception as e:
                logger.error(f"[cleanup] 关闭 handler {key} 时出错: {e}")
            finally:
                ACCOUNT_HANDLER_CACHE.pop(key, None)
    logger.info(f"[cleanup] 清理完成")

async def _serve_port_resilient(port: int, shutdown_event: asyncio.Event):
    delay = float(os.getenv("BATCH_RESTART_BASE_DELAY", "2"))
    max_delay = float(os.getenv("BATCH_RESTART_MAX_DELAY", "60"))
    while not shutdown_event.is_set():
        server = _make_server(port)
        sock = None
        try:
            try:
                sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                sock.bind((server.config.host, port))
                sock.listen(2048)
            except OSError as e:
                if e.errno in (errno.EADDRINUSE, errno.EACCES):
                    logger.error(f"[pid={os.getpid()}] 端口 {port} 无法监听: {e}")
                    await asyncio.sleep(delay)
                    delay = min(max_delay, delay * 2)
                    continue
                raise

            logger.info(f"[pid={os.getpid()}] 端口 {port} 开始监听")

            # 创建任务：server.serve 和 等待 shutdown_event
            serve_task = asyncio.create_task(server.serve(sockets=[sock]))
            wait_task = asyncio.create_task(shutdown_event.wait())

            # 等待任意一个任务完成
            done, pending = await asyncio.wait(
                [serve_task, wait_task],
                return_when=asyncio.FIRST_COMPLETED
            )

            if wait_task in done:
                logger.info(f"准备调用 cleanup_handler")
                try:
                    await cleanup_handlers()
                    logger.info(f"cleanup_handlers 执行完毕")
                except Exception as e:
                    logger.error(f"cleanup_handlers 执行异常: {e}", exc_info=True)
                # shutdown_event 被设置，主动停止 server
                logger.info(f"[pid={os.getpid()}] 端口 {port} 收到关闭信号，正在停止...")
                server.should_exit = True
                logger.info(f"准备调用 serve_task 正常退出")
                try:
                    await serve_task
                    logger.info(f"serve_task 正常退出")
                except Exception as e:
                    logger.error(f"serve_task 异常退出: {e}", exc_info=True)
                    
              
            else:
                # server 自行退出（异常或达到限制）
                pass

            # 取消剩余任务
            for task in pending:
                task.cancel()
                try:
                    await task
                except asyncio.CancelledError:
                    pass

        except asyncio.CancelledError:
            server.should_exit = True
            raise
        except Exception as e:
            logger.error(f"端口 {port} 服务异常退出: {e}")
        else:
            logger.warning(
                f"[pid={os.getpid()}] 端口 {port} 服务已退出 "
                f"(started={getattr(server, 'started', None)} should_exit={getattr(server, 'should_exit', None)} force_exit={getattr(server, 'force_exit', None)})"
            )
        finally:
            if sock is not None:
                try:
                    sock.close()
                except Exception:
                    pass

        if shutdown_event.is_set() or server.should_exit:
            break
        await asyncio.sleep(delay)
        delay = min(max_delay, delay * 2)


async def _wait_port_ready(host: str, port: int, shutdown_event: asyncio.Event, timeout: float = 5.0) -> bool:
    loop = asyncio.get_running_loop()
    end = loop.time() + timeout
    while loop.time() < end and not shutdown_event.is_set():
        try:
            reader, writer = await asyncio.open_connection(host, port)
            writer.close()
            try:
                await writer.wait_closed()
            except Exception:
                pass
            return True
        except Exception:
            await asyncio.sleep(0.2)
    return False


async def _log_ports_ready(ports, shutdown_event: asyncio.Event):
    host = os.getenv("BATCH_READY_CHECK_HOST", "127.0.0.1")
    timeout = float(os.getenv("BATCH_READY_CHECK_TIMEOUT", "5"))
    for port in ports:
        ok = await _wait_port_ready(host, port, shutdown_event, timeout=timeout)
        if ok:
            logger.info(f"[pid={os.getpid()}] 端口 {port} 监听就绪")
        else:
            logger.error(f"[pid={os.getpid()}] 端口 {port} 监听未就绪(超时)")



def start_server_on_port(port):
    """在指定端口启动服务器"""
    try:
        global SERVER_PORT
        SERVER_PORT = port
        LOCAL_NODE_KEYS.add(f"{LOCAL_IP}:{port}")

        logger.info(f"[pid={os.getpid()}] 端口 {port} 启动中...")
        limit_concurrency, limit_max_requests, timeout_keep_alive = _uvicorn_limits()
        uvicorn.run(
            app,
            host="0.0.0.0",
            port=port,
            loop="asyncio",
            limit_concurrency=limit_concurrency,
            limit_max_requests=limit_max_requests,
            timeout_keep_alive=timeout_keep_alive,
            access_log=False,
        )
    except Exception as e:
        logger.error(f"端口 {port} 启动失败: {e}")


def _run_ports_chunk(ports):
    asyncio.run(start_multiple_ports_for_ports(ports))


def start_multiple_ports():
    """批量启动多个端口"""

    try:
        multiprocessing.set_start_method("spawn", force=True)
    except RuntimeError:
        pass

    start_port = int(os.getenv("BATCH_START_PORT", "5000"))
    port_count = int(os.getenv("BATCH_PORT_COUNT", "151"))
    mode = os.getenv("BATCH_MODE", "group")

    if mode == "single":
        asyncio.run(start_multiple_ports_async(start_port=start_port, port_count=port_count))
        return

    ports_per_process_env = int(os.getenv("PORTS_PER_PROCESS", "0"))
    if ports_per_process_env > 0:
        ports_per_process = ports_per_process_env
    else:
        cpu_count = os.cpu_count() or 8
        target_proc = max(4, min(cpu_count, port_count))
        ports_per_process = max(1, (port_count + target_proc - 1) // target_proc)

    ports = list(range(start_port, start_port + port_count))
    chunks = [ports[i:i + ports_per_process] for i in range(0, len(ports), ports_per_process)]
    processes = []
    stop_requested = False

    def signal_handler(sig, frame):
        nonlocal stop_requested
        stop_requested = True
        logger.info("\n正在关闭所有服务进程...")
        for info in processes:
            p = info.get("process")
            if p and p.is_alive():
                p.terminate()
        for info in processes:
            p = info.get("process")
            try:
                if p:
                    p.join(timeout=5)
            except Exception:
                pass
        sys.exit(0)

    signal.signal(signal.SIGINT, signal_handler)
    if hasattr(signal, "SIGTERM"):
        signal.signal(signal.SIGTERM, signal_handler)

    logger.info(f"正在启动 {len(ports)} 个端口，分 {len(chunks)} 个进程 (PORTS_PER_PROCESS={ports_per_process})")
    for chunk in chunks:
        p = multiprocessing.Process(target=_run_ports_chunk, args=(chunk,))
        p.start()
        logger.info(f"已启动子进程 pid={p.pid} 负责端口段 {chunk[0]}-{chunk[-1]}")
        processes.append({"process": p, "ports": chunk, "restart_delay": 0.0, "last_start": time.time()})
        time.sleep(0.2)

    monitor_interval = float(os.getenv("BATCH_MONITOR_INTERVAL", "2"))
    reset_seconds = float(os.getenv("BATCH_RESTART_RESET_SECONDS", "60"))
    base_delay = float(os.getenv("BATCH_RESTART_BASE_DELAY", "2"))
    max_delay = float(os.getenv("BATCH_RESTART_MAX_DELAY", "60"))
    max_restarts = int(os.getenv("BATCH_MAX_RESTARTS_PER_CHUNK", "0"))
    disable_restart = os.getenv("BATCH_DISABLE_RESTART", "1").strip().lower() in {"1", "true", "yes"}

    while True:
        if stop_requested:
            break
        all_exited = True
        for info in processes:
            p = info["process"]
            if p.is_alive():
                all_exited = False
                continue

            exitcode = p.exitcode
            chunk = info["ports"]

            if exitcode is None:
                all_exited = False
                continue

            if stop_requested:
                continue

            if disable_restart:
                logger.error(f"进程 {p.pid} 端口段 {chunk[0]}-{chunk[-1]} 已退出(exitcode={exitcode})，已禁用自动重启")
                continue
            if max_restarts > 0 and info.get("restarts", 0) >= max_restarts:
                logger.error(f"进程 {p.pid} 端口段 {chunk[0]}-{chunk[-1]} 重启次数已达上限，停止重启")
                continue

            now = time.time()
            if now - info.get("last_start", now) >= reset_seconds:
                info["restart_delay"] = 0.0
                info["restarts"] = 0

            if info["restart_delay"] <= 0:
                info["restart_delay"] = base_delay
            else:
                info["restart_delay"] = min(max_delay, info["restart_delay"] * 2)
            delay = info["restart_delay"]
            logger.error(f"进程 {p.pid} 端口段 {chunk[0]}-{chunk[-1]} 已退出(exitcode={exitcode})，{delay:.1f}s 后重启")
            time.sleep(delay)

            new_p = multiprocessing.Process(target=_run_ports_chunk, args=(chunk,))
            new_p.start()
            info["process"] = new_p
            info["last_start"] = time.time()
            info["restarts"] = info.get("restarts", 0) + 1
            all_exited = False

        if all_exited:
            break
        time.sleep(monitor_interval)


async def start_multiple_ports_async(start_port: int = 5000, port_count: int = 151):
    ports = list(range(start_port, start_port + port_count))
    await start_multiple_ports_for_ports(ports)


async def start_multiple_ports_for_ports(ports):
    for port in ports:
        LOCAL_NODE_KEYS.add(f"{LOCAL_IP}:{port}")

    await _startup()

    shutdown_event = asyncio.Event()
    try:
        loop = asyncio.get_running_loop()
        if hasattr(signal, "SIGTERM"):
            loop.add_signal_handler(signal.SIGTERM, shutdown_event.set)
        loop.add_signal_handler(signal.SIGINT, shutdown_event.set)
    except Exception:
        pass

    tasks = [asyncio.create_task(_serve_port_resilient(port, shutdown_event)) for port in ports]
    tasks.append(asyncio.create_task(_log_ports_ready(ports, shutdown_event)))

    try:
        await asyncio.gather(*tasks)
    except KeyboardInterrupt:
        pass
    finally:
        shutdown_event.set()
        await asyncio.gather(*tasks, return_exceptions=True)
        await _shutdown()


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == "batch":
        start_multiple_ports()
    else:
        port = int(sys.argv[1]) if len(sys.argv) > 1 else int(os.getenv("PORT", "5000"))
        SERVER_PORT = port
        LOCAL_NODE_KEYS.add(f"{LOCAL_IP}:{port}")

        limit_concurrency, limit_max_requests, timeout_keep_alive = _uvicorn_limits()
        logger.info(f"[pid={os.getpid()}] 端口 {port} 启动中...")
        uvicorn.run(
            app,
            host="0.0.0.0",
            port=port,
            workers=1,
            loop="asyncio",
            limit_concurrency=limit_concurrency,
            limit_max_requests=limit_max_requests,
            timeout_keep_alive=timeout_keep_alive,
            access_log=False,
        )
