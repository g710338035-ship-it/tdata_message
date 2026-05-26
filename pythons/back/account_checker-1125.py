import os
import asyncio
import hashlib
import phonenumbers
from phonenumbers import geocoder
import glob
import time

# FastAPI / Uvicorn
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
import uvicorn
# 导入新增的代理处理模块
from proxy_handler import parse_proxytul,parse_proxy, test_proxy
# 导入重构后的处理类
from tdata_processor import TelegramAccountHandler


# 导入拆分后的业务逻辑模块
from account_service import (   
    run_tdesktop_decrypter,
    build_telethon_session,
)
import traceback

import logging

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.StreamHandler(),                 # 控制台输出
        logging.FileHandler("account_checker.log", encoding="utf-8")  # 写入文件
    ]
)
logger = logging.getLogger(__name__)

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

TASK_SEMAPHORE = asyncio.Semaphore(20) 

GLOBAL_API_ID = 2040
GLOBAL_API_HASH = 'b18441a1ff607e10a989891a5462e627'

from mapdc_cache import init_global_dc_cache, GLOBAL_DC_CACHE
       
# -------------------------- 节点管理核心配置 --------------------------
NODE_CACHE = {}  # 节点缓存 {node_key: {ip, port, load, ...}}
ACCOUNT_NODE_BIND = {}  # 账号-节点绑定 {account_key: node_key}
NODE_HEARTBEAT_TIMEOUT = 30  # 节点心跳超时（秒）
NODE_MAX_LOAD = 50  # 节点最大负载
NODE_LOCK = asyncio.Lock()
BIND_LOCK = asyncio.Lock()

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

async def update_node_heartbeat(node_key: str):
    """更新节点心跳"""
    async with NODE_LOCK:
        if node_key in NODE_CACHE:
            NODE_CACHE[node_key]["last_heartbeat"] = time.time()
            NODE_CACHE[node_key]["status"] = "online"
            return True
    logger.warning(f"节点不存在：{node_key}")
    return False

async def update_node_load(node_key: str, delta: int):
    """更新节点负载"""
    async with NODE_LOCK:
        if node_key in NODE_CACHE:
            new_load = max(0, NODE_CACHE[node_key]["load"] + delta)
            NODE_CACHE[node_key]["load"] = new_load
            NODE_CACHE[node_key]["status"] = "online" if new_load <= NODE_MAX_LOAD else "busy"
            return True
    return False

async def get_healthy_nodes():
    """获取健康节点列表"""
    async with NODE_LOCK:
        now = time.time()
        healthy = []
        for key, info in NODE_CACHE.items():
            if (now - info["last_heartbeat"] <= NODE_HEARTBEAT_TIMEOUT 
                and info["status"] == "online"):
                healthy.append({
                    "node_key": key,
                    "load": info["load"],
                    "weight": info["weight"]
                })
        # 按负载/权重排序
        return sorted(healthy, key=lambda x: x["load"] / x["weight"])

async def get_account_bind_node(account_key: str):
    """获取账号绑定的健康节点"""
    async with BIND_LOCK:
        node_key = ACCOUNT_NODE_BIND.get(account_key)
    if not node_key:
        return None
    # 检查节点是否健康
    healthy_nodes = await get_healthy_nodes()
    return node_key if node_key in [n["node_key"] for n in healthy_nodes] else None

async def bind_account_node(account_key: str, node_key: str):
    """绑定账号与节点"""
    async with BIND_LOCK:
        ACCOUNT_NODE_BIND[account_key] = node_key
    logger.info(f"账号绑定节点：{account_key} -> {node_key}")
    return True

def generate_account_key(tdata_path: str, phone: str = ""):
    """生成账号唯一标识"""
    if phone:
        return hashlib.md5(phone.encode()).hexdigest()
    return hashlib.md5(os.path.abspath(tdata_path).encode()).hexdigest()


        
# 使用线程锁保护全局缓存读写
async def get_account_handler(tdata_path, api_id, api_hash, proxy_str=None):
    # 生成唯一key（逻辑不变）
    normalized_path = os.path.normpath(tdata_path)
    absolute_path = os.path.abspath(normalized_path)
    key = hashlib.md5(absolute_path.encode()).hexdigest()
    
 
    
    async with GLOBAL_CACHE_LOCK:
        if key in ACCOUNT_HANDLER_CACHE:
            logger.info(f"[get_account_handler] 复用已有 handler: key={key}")
            handler = ACCOUNT_HANDLER_CACHE[key]
            # 更新代理配置（如果发生变化）
            if handler.proxy_str != proxy_str:
                handler.proxy_str = proxy_str
                handler.proxy = parse_proxytul(proxy_str) if proxy_str else None
        else:
            logger.info(f"[get_account_handler] 新建 handler: key={key}, api_id={api_id}, proxy={proxy_str}")
            # 直接创建实例，而非协程
            ACCOUNT_HANDLER_CACHE[key] = TelegramAccountHandler(
                tdata_path=tdata_path,
                api_id=api_id,
                api_hash=api_hash,
                proxy_str=proxy_str
            )
    
    handler = ACCOUNT_HANDLER_CACHE[key]
    logger.info(
        f"[get_account_handler] session_cache 状态: client={handler.session_cache['client']}, "
        f"is_connected={handler.session_cache['is_connected']}"
    )
    return handler  # 返回实例，而非协程


# -------------------------- FastAPI 应用 --------------------------
app = FastAPI(title="Telegram Account Checker", default_response_class=JSONResponse)





# （可选）接口认证：防止非法调用，建议设置自定义密钥
#AUTH_KEY = "your_secure_api_key_2024"  # 替换为自己的安全密钥
"""综合操作接口，支持单账号与批量异步处理（队列模式）"""
@app.route("/telegram_action", methods=["POST"])
async def telegram_action(request: Request):
    try:
        data = await request.json()
        if not data:
            return JSONResponse(content={"status": False, "message": "请求参数为空"}, status_code=400)

        # 是否为批量操作
        is_batch = isinstance(data.get('batch'), list) and len(data.get('batch', [])) > 0

        if is_batch:
            logger.info(f"[is_batch] 开始批量任务，共 {len(data['batch'])} 个")

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

            # worker 协程：使用 TASK_SEMAPHORE 控制全局并发
            async def worker(worker_id: int):
                while not task_queue.empty():
                    idx, task = await task_queue.get()
                    try:
                        async with TASK_SEMAPHORE:
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
            max_workers = 20
            workers = [asyncio.create_task(worker(i)) for i in range(max_workers)]

            await task_queue.join()  # 等待所有任务完成
            for w in workers:
                w.cancel()

            return JSONResponse(content={"status": True, "message": "批量任务完成", "data": batch_results})

        else:
            # 单账号操作逻辑
            if 'action' not in data:
                return JSONResponse(content={"status": False, "message": "缺少action"}, status_code=400)

            result = await process_single_action(data)
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
    async with TASK_SEMAPHORE:
        try:
            action = task['action']
            tdata_path = task.get('tdata_path')
            # 需要tdata_path的操作列表
            tdata_required_actions = [
                "get_account_info", "set_online", "set_offline","update_photo", "update_nickname", "update_username", "update_bio",
                "change_password", "delete_all_contacts", "leave_all_groups","logout_other_sessions", "send_messages", "get_groups", 
                "get_contacts","get_new_messages", "get_history", "count_total_unread","mark_session_as_read", "block_user", "delete_chat_history","get_common_groups", "deleteUser"
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
                session_files = glob.glob(os.path.join(tdata_path, "temp_*.session"))
                has_valid_session =None
                account_info={}
                session_path = None 
                if action=='set_online':
                        for f in session_files:
                            try:
                                os.remove(f)
                                logger.info(f"删除残留的 session 文件: {f}")
                            except Exception as e:
                                logger.warning(f"删除 session 文件失败: {f}, 错误: {e}")
                else:
                     has_valid_session = any(os.path.isfile(f) for f in session_files)
                     logger.info(f"session 文件: ")
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
                        auth_key_hex= task.get('auth_key_hex')
                    )

                    # 计算process_single_session运行时间
                    process_end_time = time.perf_counter()
                    process_duration = (process_end_time - process_start_time) * 1000  # 转换为毫秒
                    logger.info(f"process_single_session 执行完成，耗时: {process_duration:.2f} 毫秒，task_id: {task_id}")
                    
                    # 检查生成结果
                    if account_info["error"]:
                        # 删除生成失败时残留的 session 文件
                        session_files = glob.glob(os.path.join(tdata_path, "temp_*.session"))
                        for f in session_files:
                            try:
                                os.remove(f)
                                logger.info(f"删除残留的 session 文件: {f}")
                            except Exception as e:
                                logger.warning(f"删除 session 文件失败: {f}, 错误: {e}")
                                
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
                        
                    logger.info(f"tdata目录 {tdata_path} 成功生成session文件{account_info}")
                   
                    
                else:
                    # 使用现有 session 文件
                    valid_session_files = [f for f in session_files if os.path.isfile(f)]
                    target_session = valid_session_files[0]
                    session_path = os.path.realpath(target_session)
                    logger.info(f"直接使用已存在的session文件: {session_path}")
            # 为每个任务创建独立的处理器
            handler = await  get_account_handler(
                tdata_path=session_path,
                api_id=api_id,
                api_hash=api_hash,
                proxy_str=task.get('proxy')
            )
            
            execute_start_time = time.perf_counter()
            # 执行具体操作
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
            logger.info(f"tdata目录 {result} 返回信息{result}")
            if not result.get("status"):  # 用 get() 避免 KeyError，兼容 status 不存在的情况
                # 1. 定义精准的 session 文件匹配规则（仅目标目录下的 temp_*.session）
                session_pattern = os.path.join(tdata_path, "temp_*.session")
                # 2. 获取匹配的文件列表（glob.glob 返回空列表时无异常，无需额外处理）
                session_files = glob.glob(session_pattern)
                
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
                            os.remove(file_path)
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
        handler = await   get_account_handler(
            tdata_path=data.get('tdata_path'),
            api_id=int(api_id),
            api_hash=api_hash,
            proxy_str=data.get('proxy')
        )
        return await execute_action(handler, action, data)
"""执行具体操作的通用函数"""
async def execute_action(handler, action, params):
    
    try:
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
            logger.info(f"clients 信息{clients}")
            if clients is not None:
                return await handler.set_online(clients)
            return await handler.set_online()
            
        elif action == "set_offline":
            result=await handler.set_offline()
            
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
                first_msg_id=int(params.get('first_msg_id', 0))
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

async def process_single_session(tdata_path, tdata_phone, account_id, api_id, api_hash,proxy_str,tguser_id,main_dc_id,auth_key_hex):
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
            logger.info(f"[proxy_str] 信息: proxy_str={proxy_str}")
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
    logger.info(f"[proxy] 信息: proxy={proxy}")
    try:
       
        session = await build_telethon_session(
            tdata_path=tdata_path,
            auth_key_hex=auth_key_hex,
            main_dc_id=main_dc_id,
            user_id=tguser_id,
            api_id=api_id,
            api_hash=api_hash,
            phone=tdata_phone,
            proxy=proxy  # 新增代理支持
        )
        logger.info(f"[session] 信息: proxy_session={session}")
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
    logger.info(f"[single_result] 信息: single_result={single_result}")
    return single_result    

def sync_build_session(tdata_path, auth_key_hex, main_dc_id, user_id, api_id, api_hash, phone, proxy):
    """同步构建会话（在已有事件循环中运行）"""
    try:
        # 在现有事件循环中运行，而不是创建新的事件循环
        loop = asyncio.get_running_loop()
        # 使用 run_coroutine_threadsafe 在现有循环中运行协程
        future = asyncio.run_coroutine_threadsafe(
            build_telethon_session(
                tdata_path=tdata_path,
                auth_key_hex=auth_key_hex,
                main_dc_id=main_dc_id,
                user_id=user_id,
                api_id=api_id,
                api_hash=api_hash,
                phone=phone,
                proxy=proxy
            ),
            loop
        )
        result = future.result(timeout=60)  # 60秒超时
        return result
    except Exception as e:
        logger.error(f"同步构建会话失败: {e}")
        return {"success": False, "error": str(e)}
        
# -------------- 新增：批量账号解析接口 /batch_check_account --------------
@app.route("/batch_check_account", methods=["POST"])
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
    logger.info(f"[single_result] 信息: single_result={single_result}")    
    return single_result

  
if __name__ == '__main__':
    import sys
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 5000
    
    dc_map = asyncio.run(init_global_dc_cache(GLOBAL_API_ID, GLOBAL_API_HASH))
    #logger.info(f"[DC缓存] 当前 dc_map: {dc_map}")
    #logger.info(f"[DC缓存] GLOBAL_DC_CACHE 内容: {GLOBAL_DC_CACHE}")
    #import asyncio
    #asyncio.run(init_global_dc_cache(GLOBAL_API_ID, GLOBAL_API_HASH))
    
    asyncio.run(register_node(f"{LOCAL_IP}:{port}"))
    logger.info(f"启动服务：{LOCAL_IP}:{port}")
    
    # 单worker进程配置
    uvicorn.run(
        "account_checker:app", 
        host="0.0.0.0", 
        port=port, 
        workers=1,  # 单进程
        loop="asyncio",
        limit_concurrency=2000,  # 提高并发限制
        limit_max_requests=100,  # 自动重启避免内存泄漏
        timeout_keep_alive=5
    )