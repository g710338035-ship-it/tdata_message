import argparse
import json
import os
import asyncio
import subprocess
import hashlib
from datetime import datetime
from opentele.td import TDesktop
from opentele.api import UseCurrentSession
from opentele.exception import OpenTeleException
from telethon import TelegramClient
from telethon.sessions import MemorySession, StringSession
from telethon.tl.functions.help import GetConfigRequest
from telethon.errors import AuthKeyError
from telethon.errors import PeerIdInvalidError, FloodWaitError, InputUserDeactivatedError,RPCError
import phonenumbers
from phonenumbers import geocoder
import glob

import stat
import pwd
import grp
import subprocess

# FastAPI / Uvicorn
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
import uvicorn
# 导入新增的代理处理模块
from proxy_handler import parse_proxy, test_proxy
# 导入重构后的处理类
from tdata_processor import TelegramAccountHandler


# 导入拆分后的业务逻辑模块
from account_service import (
    set_session_permissions,
    run_tdesktop_decrypter,
    build_telethon_session,
    verify_and_extract_info,
    timestamp_to_datetime,
    get_latest_dc_config
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

# 全局缓存和进程间通信队列
ACCOUNT_HANDLER_CACHE = {}
GLOBAL_CACHE_LOCK = asyncio.Lock() 

async def get_account_handler(tdata_path, api_id, api_hash, proxy_str=None):
    # 生成唯一key（逻辑不变）
    normalized_path = os.path.normpath(tdata_path)
    absolute_path = os.path.abspath(normalized_path)
    key = hashlib.md5(absolute_path.encode()).hexdigest()
    
    # 使用线程锁保护全局缓存读写
    
    async with GLOBAL_CACHE_LOCK:
        if key in ACCOUNT_HANDLER_CACHE:
            logger.info(f"[get_account_handler] 复用已有 handler: key={key}")
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
@app.route("/telegram_action", methods=["POST"])
async def telegram_action(request: Request):
    """综合操作接口，支持单账号操作和批量异步处理"""
    try:
        data = await request.json()
        if not data:
            return JSONResponse(content={"status": False, "message": "请求参数为空"}, status_code=400)
        

        # 验证必要的公共参数
        if not all(k in data for k in ['api_id', 'api_hash']):
            return JSONResponse(content={"status": False, "message": "缺少api_id或api_hash"}, status_code=400)

           

        # 判断是否为批量操作
        is_batch = isinstance(data.get('batch'), list) and len(data.get('batch', [])) > 0
        
        if is_batch:
            logger.info(f"[is_batch] 开始")
            # 批量处理逻辑
            batch_tasks = []
            batch_results = []
            
            # 为每个任务创建处理函数
            for idx, task in enumerate(data['batch']):
                if not isinstance(task, dict) or 'action' not in task:
                    batch_results.append({
                        "task_id": idx,
                        "status": False,
                        "message": "无效的任务格式，缺少action字段",
                        "data": {}
                    })
                    continue
                
                # 创建带索引的任务，便于追踪结果
                batch_tasks.append(
                    process_batch_task(
                        task_id=idx,
                        task=task,
                        api_id=int(data['api_id']),
                        api_hash=data['api_hash']
                    )
                )
            
            # 并发执行所有批量任务
            batch_results = await asyncio.gather(*batch_tasks)
            return JSONResponse(content={"status": True, "message": "批量完成", "data": batch_results})
       
            
        else:
            # 单账号操作逻辑
            if 'action' not in data:
                return JSONResponse(content={"status": False, "message": "缺少action"}, status_code=400)
                
            result = await  process_single_action(data)
            return JSONResponse(content=result)

    except Exception as e:
        error_detail = traceback.format_exc()
        return JSONResponse(content={"status": False, "message": str(e), "error_detail": traceback.format_exc()}, status_code=500)
"""处理单个批量任务"""
async def process_batch_task(task_id, task, api_id, api_hash):
    
    try:
        action = task['action']
        # 需要tdata_path的操作列表
        tdata_required_actions = [
            "get_account_info", "set_online", "set_offline",
            "update_photo", "update_nickname", "update_username", "update_bio",
            "change_password", "delete_all_contacts", "leave_all_groups",
            "logout_other_sessions", "send_messages", "get_groups", "get_contacts",
            "get_new_messages", "get_history", "count_total_unread",
            "mark_session_as_read", "block_user", "delete_chat_history",
            "get_common_groups", "deleteUser"
        ]
        # 验证tdata_path参数
        tdata_path = task.get('tdata_path')
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
            user_id=task.get('user_id')
            session_path = None
            # 检查tdata目录下是否有session文件
            session_files = glob.glob(os.path.join(tdata_path, "temp_*.session"))
            has_valid_session = any(os.path.isfile(f) for f in session_files)
            account_info={}
            session_path = None 
            # 如果没有有效的session文件，则通过process_single_account获取
            if not has_valid_session:
                logger.info(f"tdata目录 {tdata_path} 中未找到session文件，开始生成...")
                
                # 调用process_single_account生成session文件
                # 注意：这里需要构造process_single_account所需的参数
                account_info = await process_single_session(
                    tdata_path=tdata_path,
                    tdata_phone=task.get('phone', ''),  # 从任务参数中获取手机号
                    account_id=str(task_id),  # 用任务ID作为临时account_id
                    api_id=api_id,
                    api_hash=api_hash,
                    proxy_str=task.get('proxy')
                )
                
                # 检查生成结果
                if account_info["error"]:
                    return {
                        "task_id": task_id,
                        "status": False,
                        "message": f"生成session文件失败: {account_info['error']}",
                        "data": {}
                    }
                
                # 再次检查session文件是否生成成功
                session_files_after = glob.glob(os.path.join(tdata_path, "temp_*.session"))
                if not any(os.path.isfile(f) for f in session_files_after):
                    return {
                        "task_id": task_id,
                        "status": False,
                        "message": f"生成session文件后仍未找到有效文件: {tdata_path}",
                        "data": {}
                    }
                #user_id=account_info['result']['user_id'];
                session_path=account_info['result']['session_path'];
               
                
                logger.info(f"tdata目录 {tdata_path} 成功生成session文件")
            else:
                valid_session_files = [f for f in session_files if os.path.isfile(f)]
    
                # 取第一个有效文件（可根据业务调整，如按文件名排序、按修改时间排序）
                target_session = valid_session_files[0]
                
                # 获取文件的真实路径（处理符号链接、相对路径等，确保路径绝对且有效）
                session_path = os.path.realpath(target_session)
                
                #session_path = os.path.join(tdata_path, f"temp_{user_id}.session")
                logger.info(f"直接使用已存在的session文件: {session_path}")
        # 为每个任务创建独立的处理器
        handler = await  get_account_handler(
            tdata_path=session_path,
            api_id=api_id,
            api_hash=api_hash,
            proxy_str=task.get('proxy')
        )
        
        
        # 执行具体操作
        result = await execute_action(handler, action, task)
        if action == 'set_online':
            result["account_info"] = account_info
        # 添加任务ID
        result["task_id"] = task_id
        
        return result
        
    except Exception as e:
        return {
            "task_id": task_id,
            "status": False,
            "message": f"任务执行失败: {str(e)}",
            "data": {"error": traceback.format_exc()}
        }
"""处理单个操作"""
async def process_single_action(data):
    
    action = data['action']
    
    if action == "test_proxy":
        if not data.get('proxy'):
            return {
                "status": False,
                "message": "test_proxy需要指定proxy参数",
                "data": {}
            }
        proxy_info = parse_proxy(data['proxy'])
        return await test_proxy(proxy_info, int(data['api_id']), data['api_hash'])
    else:
        handler = await   get_account_handler(
            tdata_path=data.get('tdata_path'),
            api_id=int(data['api_id']),
            api_hash=data['api_hash'],
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

# -------------- 新增：批量账号检查接口 /batch_check_account --------------
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

        
        # 4. 并发处理所有账号（使用 asyncio.gather 提高效率）
        # 构造每个账号的处理任务
        tasks = []
        for account in accounts:
            # 提取单账号参数（适配PHP传入的格式）
            tdata_path = account.get("tdata_path", "")
            tdata_phone = account.get("tdata_phone", "")
            account_id = account.get("account_id", "")  # PHP侧生成的唯一标识
            api_id = request_data.get("api_id", 0)  # 从请求全局参数获取（或读配置）
            api_hash = request_data.get("api_hash", "")
            
            # 绑定任务：每个账号独立处理
            tasks.append(
                process_single_account(
                    tdata_path=tdata_path,
                    tdata_phone=tdata_phone,
                    account_id=account_id,
                    api_id=api_id,
                    api_hash=api_hash
                )
            )
        
        # 5. 等待所有任务完成（并发执行，耗时=单个账号最长处理时间）
        batch_result = await asyncio.gather(*tasks)
        
    except Exception as e:
        # 全局异常处理：为所有未处理账号标记错误
        error_msg = f"批量处理全局异常：{str(e)}"
        for account in accounts:
            batch_result.append({
                "account_id": account.get("account_id", ""),
                "phone": account.get("tdata_phone", ""),
                "result": {},
                "error": error_msg
            })
    
    # 6. 返回批量结果（与PHP侧解析逻辑适配）
    return JSONResponse(content=batch_result, status_code=200)
# -------------- 新增：单账号处理辅助函数（批量接口依赖）--------------
async def process_single_account(tdata_path, tdata_phone, account_id, api_id, api_hash):
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
    
    # 2. 清理临时会话文件（复用原逻辑）
    temp_sessions = glob.glob(os.path.join(tdata_path, "temp_*.session"))
    for f in temp_sessions:
        try:
            os.remove(f)
        except Exception as e:
            single_result["error"] += f"（清理临时文件警告：{str(e)}）"
    
    # 3. 复用原 /check_account 接口的核心逻辑（会话生成+验证）
    session = None
    session_path = None
    logger.info(f"[decryption_result] 信息={tdata_path}")
    
    try:
        decryption_result = run_tdesktop_decrypter(tdata_path)
        logger.info(f"[DEBUG] 解密结果：{decryption_result}")
    except Exception as e:
        logger.info(f"[EXCEPTION] run_tdesktop_decrypter 出错：{e}")
        single_result["error"] = f"tdata解析异常：{e}"
        return single_result
    
    if not decryption_result.get("success"):
        single_result["error"] = f"TDesktop失败+tdata解析失败：{decryption_result.get('error', '')}"
        return single_result
    
    # 构建会话并验证
    user_id = decryption_result["user_id"]
    main_dc_id = decryption_result["main_dc_id"]
    auth_key_hex = decryption_result["auth_key_hex"]
    
     # 解析国家信息
    country = "未知"
    if tdata_phone:
        try:
            phone = tdata_phone if tdata_phone.startswith('+') else '+' + tdata_phone
            phone_number = phonenumbers.parse(phone, None)
            country = geocoder.country_name_for_number(phone_number, "zh")
        except:
            pass
    
    
    single_result["result"] = {
            "status": 1,
            "user_id": user_id,
            "auth_key": auth_key_hex,
            "phone": tdata_phone,
            "country": country,
            "account_status": "登录成功",
            'account_status_desc':'账户解析成功'
        }
    
    return single_result
    
# -------------- 新增：单账号session处理辅助函数（批量接口依赖）--------------

async def process_single_session(tdata_path, tdata_phone, account_id, api_id, api_hash,proxy_str):
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
    
    # 2. 清理临时会话文件（复用原逻辑）
    temp_sessions = glob.glob(os.path.join(tdata_path, "temp_*.session"))
    for f in temp_sessions:
        try:
            os.remove(f)
        except Exception as e:
            single_result["error"] += f"（清理临时文件警告：{str(e)}）"
    # 3. 解析代理（如果提供）
    proxy = None
    if proxy_str:
        try:
            proxy = parse_proxy(proxy_str)
            # 简单验证代理格式
            if not all(k in proxy for k in ['scheme', 'hostname', 'port']):
                raise ValueError("代理格式无效")
            single_result["result"]["proxy_used"] = f"{proxy['scheme']}://{proxy['hostname']}:{proxy['port']}"
        except Exception as e:
            single_result["result"]["proxy_warning"] = f"代理解析失败：{str(e)}，将使用无代理模式"
    # 4. 复用原 /check_account 接口的核心逻辑（会话生成+验证）
    session = None
    session_path = None
    try:
        # 步骤1：尝试通过 TDesktop 初始化会话
        tdesk = TDesktop(tdata_path)
        tdesk.LoadTData()
        
        if not tdesk.accounts:
            raise OpenTeleException("tdata中未找到有效账号")
        
        # 生成会话
        account = tdesk.accounts[0]
        session_path = os.path.join(tdata_path, f"temp_{account.UserId}.session")
        telethon_client = await tdesk.ToTelethon(
            session=session_path,
            flag=UseCurrentSession,
            api_id=api_id or config("telegram.api_id"),  # 优先用请求参数，其次读配置
            api_hash=api_hash or config("telegram.api_hash"),
            proxy=proxy  # 新增代理支持
        )
        session = telethon_client.session
        
        # 设置会话权限（复用原函数）
        perm_result, perm_msg = set_session_permissions(session_path)
        if not perm_result:
            single_result["result"]["session_warning"] = perm_msg
        
        # 步骤2：验证会话并提取账号信息（复用原函数）
        verification_result = await verify_and_extract_info(session, api_id, api_hash)
        if verification_result["success"]:
            # 处理成功结果（与原接口返回格式一致）
            single_result["result"] = {
                "status": 1,
                "user_id": verification_result["user_id"],
                "username": verification_result["username"],
                "nickname": verification_result["nickname"],
                "phone": verification_result["phone"] or tdata_phone,
                "country": verification_result["country"],
                "is_authorized": True,
                "online": 1 if verification_result["status_info"].get("is_online") else 0,
                "account_status": verification_result["account_status"],
                "account_status_desc": verification_result["account_status_desc"],
                "session_path": session_path
            }
        else:
            # 处理验证失败
            single_result["error"] = verification_result["error"]
            single_result["result"] = {
                "status": 0,
                "account_status": verification_result["account_status"],
                "account_status_desc": verification_result["account_status_desc"]
            }
    
    except OpenTeleException as e:
        # TDesktop 初始化失败，尝试解析 tdata（复用原分支逻辑）
        try:
            decryption_result = run_tdesktop_decrypter(tdata_path)
            if not decryption_result.get("success"):
                single_result["error"] = f"TDesktop失败+tdata解析失败：{str(e)} | {decryption_result['error']}"
                return single_result
            
            # 构建会话并验证
            user_id = decryption_result["user_id"]
            main_dc_id = decryption_result["main_dc_id"]
            auth_key_hex = decryption_result["auth_key_hex"]
            session = await build_telethon_session(
                tdata_path=tdata_path,
                auth_key_hex=auth_key_hex,
                main_dc_id=main_dc_id,
                user_id=user_id,
                api_id=api_id,
                api_hash=api_hash,
                phone=tdata_phone,
                proxy=proxy  # 新增代理支持
            )
            verification_result = await verify_and_extract_info(session, api_id, api_hash)
            if verification_result["success"]:
                single_result["result"] = {
                    "status": 1,
                    "user_id": user_id,
                    "auth_key": auth_key_hex,
                    "phone": tdata_phone,
                    "account_status": "正常"
                }
            else:
                single_result["error"] = verification_result["error"]
        except Exception as sub_e:
            single_result["error"] = f"TDesktop失败+解析异常：{str(e)} | {str(sub_e)}"
    
    except Exception as e:
        # 其他未知异常
        single_result["error"] = f"单账号处理异常：{str(e)}"
    
    # 4. 确保客户端断开连接（避免资源泄漏）
    if "client" in locals() and locals()["client"] and locals()["client"].is_connected():
        await locals()["client"].disconnect()
    logger.info(f"[single_result] 信息: single_result={single_result}")
    return single_result    
  
if __name__ == '__main__':
    # 生产环境建议使用Gunicorn启动
    # 启动命令示例: gunicorn -w 4 -b 0.0.0.0:5000 account_checker:app
    import sys
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 5000
    uvicorn.run("account_checker:app", host="0.0.0.0", port=port, reload=False)
