import os
import asyncio
import random
import time
from telethon.tl.functions.account import (
    UpdateProfileRequest,
    UpdateUsernameRequest,
    UpdatePasswordSettingsRequest,
    GetAuthorizationsRequest,  # 获取其他设备用
    ResetAuthorizationRequest  # 退出其他设备用
)
from telethon import TelegramClient

from telethon.tl.functions.contacts import (
    GetContactsRequest, BlockRequest,
    DeleteContactsRequest  # 删除好友用
)

from telethon.tl.functions.channels import (
    GetParticipantsRequest,JoinChannelRequest,
    LeaveChannelRequest  # 退出群聊/频道用
)

from telethon.tl.functions.photos import (
    UploadProfilePhotoRequest
)
from telethon.tl.types import (
    PeerUser,  # 用于指定删除的好友
    User,Channel, Chat,ChannelParticipantsSearch
)
from telethon.tl.types import InputPeerUser,InputPeerChat, InputPeerChannel
from utils import parse_status
from proxy_handler import parse_proxy
from telethon.errors import (
    PeerIdInvalidError,
    ChannelPrivateError
)
from telethon.tl.functions.messages import DeleteMessagesRequest,ImportChatInviteRequest,DeleteChatUserRequest
from telethon.errors import PeerIdInvalidError,InputUserDeactivatedError,UsernameOccupiedError, UsernameInvalidError,FloodWaitError
from telethon.network.connection.tcpobfuscated import ConnectionTcpObfuscated
from telethon.tl.functions.users import GetFullUserRequest
import functools
import base64

from database import MySQLDatabase
from cache import RedisCache
from monitor import TelegramMonitor
from telegram_api_governor import TelegramApiGovernor


from logging_config import get_logger

logger = get_logger(__name__)

#logger.disabled = False 

#from account_service import regenerate_auth_key_auto_with_client

# 全局缓存配置（生产环境建议替换为Redis）
GROUP_CACHE = {}         # 群组缓存：key=账号ID_tdata路径, value={timestamp, data}
CACHE_EXPIRE = 300       # 缓存有效期（秒）- 5分钟
ENTITY_CACHE_EXPIRE = 300
ME_CACHE_EXPIRE = 60
MEMBERSHIP_CACHE_EXPIRE = 120

class TelegramAccountHandler:  
    
    def __init__(self, tdata_path, api_id, api_hash, proxy_str=None):
        self.tdata_path = tdata_path
        self.api_id = api_id
        self.api_hash = api_hash
        self.proxy_str = proxy_str
        self._lock = asyncio.Lock()  # 异步锁确保缓存操作安全
        
       # 生成账号唯一标识
        self.account_id = f"{os.path.basename(tdata_path)}"
        
        # 初始化数据库
        self.db = MySQLDatabase(self.account_id) 
        
        # 初始化缓存
        self.cache = RedisCache(self.account_id) 
        # --- 初始化控制 ---
        self._init_lock = asyncio.Lock()   # 避免重复初始化
        self._initialized = False          # 是否已成功初始化
        # 监听器实例
        self.monitor = None
        # 监听器后台任务引用
        self._monitor_bg_task = None
        self._membership_cache = {}
        
        # 不直接初始化session_cache，而是在首次使用时检查
        if not hasattr(self, 'session_cache'):
            self.session_cache = {
                "client": None,
                "is_connected": False,
            }
            self.loop = asyncio.get_event_loop() # 绑定当前进程的事件循环
            # --- [关键修复] 使用全局单例锁代替实例锁 ---
            from async_locks import ReentrantAsyncLock, get_account_lock
            
            # 1. 强制从全局锁工厂获取该账号的锁（确保同一进程内即使 new 多个 Handler，也是同一把锁）
            self._client_io_lock = get_account_lock(f"{self.account_id}_io")
            self._client_conn_lock = get_account_lock(f"{self.account_id}_conn")
            
            # 2. 发送锁也做成全局的
            self.send_lock = get_account_lock(f"{self.account_id}_send")
            self._api_governor = TelegramApiGovernor(
                min_interval=float(os.getenv("TELEGRAM_API_MIN_INTERVAL", "0.8")),
                entity_ttl=ENTITY_CACHE_EXPIRE,
                me_ttl=ME_CACHE_EXPIRE,
                rate_lock=get_account_lock(f"{self.account_id}_api_rate")
            )
    
    async def initialize(self):
        """
        初始化数据库和缓存连接，只执行一次
        """
        if self._initialized:
            logger.info(f"数据库和缓存完成了")
            return True

        async with self._init_lock:
            if self._initialized:
                return True

            try:
                backoff = 1
                for _ in range(5):
                    try:
                        await self.db.initialize()
                        break
                    except Exception as e:
                        msg = str(e)
                        if ('Too many connections' in msg) or ('1040' in msg):
                            await asyncio.sleep(backoff)
                            backoff = min(backoff * 2, 10)
                            continue
                        raise
                    
                await self.cache.initialize()

                self._initialized = True
                logger.info(f"数据库和缓存初始化完成（仅执行一次）: {self.account_id}")
                return True

            except Exception as e:
                logger.error(f"初始化失败: {str(e)}")
                return False
     
    def _get_client_params(self):
        """获取 Telethon 客户端参数"""
        from session_utils import ensure_writable_session_file, resolve_session_file

        session_path = ensure_writable_session_file(
            resolve_session_file(self.tdata_path, self.account_id),
            user_id=self.account_id,
        )
        proxy_info = None

        # 配置代理
        if self.proxy_str:
            proxy = parse_proxy(self.proxy_str)
            proxy_info = proxy
            proxy_conf = (
                proxy["protocol"],
                proxy["ip"],
                proxy["port"],
                True,
                proxy.get("username"),
                proxy.get("password")
            ) if proxy.get("username") else (
                proxy["protocol"],
                proxy["ip"],
                proxy["port"]
            )
        else:
            proxy_conf = None

        # 如果 session 已经存在，直接用 Telethon

        return {
            "session": session_path,
            "api_id": self.api_id,
            "api_hash": self.api_hash,
            "proxy": proxy_conf
        }, proxy_info
        
    async def detect_frozen_by_bot_verification(self, client):
        """
        专门检测bot_verification冻结标记
        """
        
        try:
            me = await self._get_me_cached(client, force_refresh=True)
            user_id = me.id
            full_user = await client(GetFullUserRequest(user_id))
            #logger.info(f"检测到bot_verification:full_user={full_user}")
            # 提取bot_verification信息
            if (hasattr(full_user, 'full_user') and 
                hasattr(full_user.full_user, 'bot_verification') and 
                full_user.full_user.bot_verification):
                
                bot_ver = full_user.full_user.bot_verification
                bot_id = getattr(bot_ver, 'bot_id', 'unknown')
                description = getattr(bot_ver, 'description', '')
                icon = getattr(bot_ver, 'icon', None)
                
                logger.info(f"检测到bot_verification:user_id={user_id} bot_id={bot_id}, description='{description}'")
                
                if description and 'frozen' in description.lower():
                    return {
                        "status": "FROZEN",
                        "reason": f"账号被Telegram官方冻结: {description}",
                        "bot_id": bot_id,
                        "icon": icon,
                        "details": "检测到bot_verification冻结标记"
                    }
            
            return {"status": "NORMAL", "reason": "未检测到冻结标记"}
            
        except Exception as e:
            logger.error(f"检测bot_verification失败: {str(e)}")
            return {"status": "UNKNOWN", "reason": f"检测失败: {str(e)}"}
            
    def _launch_monitor_task(self, client):
        #return True
        """启动监听任务并保存引用"""
        if self._monitor_bg_task and not self._monitor_bg_task.done():
            self._monitor_bg_task.cancel()
        self._monitor_bg_task = asyncio.create_task(self._start_monitor_only(client))

    async def _wait_for_api_slot(self, action="default", min_interval=None):
        await self._api_governor.wait_for_slot(action=action, min_interval=min_interval)

    async def _get_me_cached(self, client, ttl=ME_CACHE_EXPIRE, force_refresh=False):
        async def _fetch_me():
            async with self._client_conn_lock:
                async with self._client_io_lock:
                    return await client.get_me()
        return await self._api_governor.get_me(
            fetcher=_fetch_me,
            force_refresh=force_refresh,
            ttl=ttl,
            action="get_me",
            min_interval=0.3,
        )

    async def _get_entity_cached(self, client, entity_ref, ttl=ENTITY_CACHE_EXPIRE, force_refresh=False):
        async def _fetch_entity(ref):
            async with self._client_conn_lock:
                async with self._client_io_lock:
                    return await client.get_entity(ref)
        return await self._api_governor.get_entity(
            entity_ref=entity_ref,
            fetcher=_fetch_entity,
            force_refresh=force_refresh,
            ttl=ttl,
            action="get_entity",
            min_interval=0.35,
        )
        
    async def set_online(self, client=None,  start_monitor=True):
        """建立长连接并缓存客户端实例，支持自动重建 session"""
        
        async with self._lock:
            if client is not None:
                
                # 检查传入的client是否已连接
                #if client.is_connected():
                self.session_cache["client"] = client
                self.session_cache["is_connected"] = True
                self._api_governor.clear()
                self._membership_cache.clear()
                                   
                # 启动监听
                if start_monitor:
                    self.session_cache["monitor_started"] = True
                    #asyncio.create_task(self._start_monitor_only(client))
                    self._launch_monitor_task(client)
                return {
                    "status": True,
                    "message": "复用已有客户端，已处于在线状态",
                    "data": {"account_status": "正常", "account_status_desc": "登录成功"},
                  #  'session_data':session_data
                }
                    
            client = self.session_cache.get("client")
    
            # 复用已连接 client
            if client and client.is_connected():
                self.session_cache["is_connected"] = True
                # 启动监听
                if start_monitor and not self.session_cache.get("monitor_started"):
                    self.session_cache["monitor_started"] = True
                    self._launch_monitor_task(client)
                return {
                    "status": True,
                    "message": "已处于在线状态",
                    "data": {"account_status": "正常", "account_status_desc": "登录成功"}
                }
    
            # 尝试重连已断开 client
            if client and not client.is_connected():
                try:
                    #await client.connect()
                    async with self._client_conn_lock:
                        await client.connect()
                    if await client.is_user_authorized():
                        self.session_cache["client"] = client
                        self.session_cache["is_connected"] = True
                        self._api_governor.clear()
                        self._membership_cache.clear()
                        # 启动监听
                        if start_monitor and not self.session_cache.get("monitor_started"):
                            self.session_cache["monitor_started"] = True
                         
                            self._launch_monitor_task(client)
                        return {
                            "status": True,
                            "message": "重新连接成功并保持长连接",
                            "data": {"account_status": "正常", "account_status_desc": "登录成功"}
                        }
                    else:
                        await client.disconnect()
                        self.session_cache["client"] = None
                        self.session_cache["is_connected"] = False
                except Exception:
                    self.session_cache["client"] = None
                    self.session_cache["is_connected"] = False
    
            # 没有可用 client 或需要重新生成 session
            client_params, proxy_info = self._get_client_params()
    
            async def create_client():
                old_client = self.session_cache.get("client")
                if old_client:
                    try:
                        await old_client.disconnect()
                    except Exception:
                        pass
                
                self.session_cache["client"] = None
                self.session_cache["is_connected"] = False
                self.session_cache["monitor_started"] = False
                
                """创建并连接 TelegramClient"""
                from session_utils import SafeSQLiteSession

                new_client = TelegramClient(
                    self.tdata_path,
                    client_params["api_id"],
                    client_params["api_hash"],
                    connection=ConnectionTcpObfuscated,
                    auto_reconnect=True,
                    proxy=client_params.get("proxy"),
                    connection_retries=1,
                    timeout=10,
                    sequential_updates=True
                )
                #await new_client.connect()
                async with self._client_conn_lock:
                    async with self._client_io_lock:
                        await new_client.connect()
                if not await new_client.is_user_authorized():
                    await new_client.disconnect()
                    raise Exception("账号未登录")
                self.session_cache["client"] = new_client
                self.session_cache["is_connected"] = True
                self._api_governor.clear()
                self._membership_cache.clear()
                return new_client
    
            try:
                new_client = await create_client()
                # FloodWait 自动处理
                new_client.flood_sleep_threshold = 120
        
                # 心跳优化
                if hasattr(new_client, "_sender") and new_client._sender:
                    new_client._sender._keepalive = 30
                    
                frozen_check = await self.detect_frozen_by_bot_verification(new_client)
                            
                if frozen_check.get("status") == "FROZEN":
                    
                    try:
                        await new_client.disconnect()
                    except:
                        pass
                        
                    return {
                        "status": False,
                        "message": "账号被冻结",
                        "data": {"account_status": "冻结", "account_status_desc": "账号被冻结"}
                    } 
                # 启动监听
                if start_monitor and not self.session_cache.get("monitor_started"):
                    self.session_cache["monitor_started"] = True
                    self._launch_monitor_task(new_client)
                return {
                    "status": True,
                    "message": "登录成功并保持长连接",
                    "data": {"account_status": "正常", "account_status_desc": "登录成功"}
                } 
    
            except Exception as e:
                err_msg = str(e)
    
                # session 被多 IP 使用 -> 删除旧 session 并重建
                if "authorization key" in err_msg and "used under two different IP addresses" in err_msg:
                    try:
                        session_file = client_params["session"]
                        if os.path.exists(session_file):
                            from session_utils import safe_remove_session_file

                            safe_remove_session_file(session_file)
                    except Exception:
                        pass
    
                    # 立即重新生成 client
                    try:
                        new_client = await create_client()
                        
                        if start_monitor and not self.session_cache.get("monitor_started"):
                            self.session_cache["monitor_started"] = True
                            #asyncio.create_task(self._start_monitor_only(new_client))
                            self._launch_monitor_task(new_client)
                        return {
                            "status": True,
                            "message": "旧 session 被占用，已重建新 session 并登录成功",
                            "data": {"account_status": "正常", "account_status_desc": "会话被重建，登录成功"}
                        }
                    except Exception as e2:
                        return {
                            "status": False,
                            "message": f"重建 session 失败: {e2}",
                            "data": {"account_status": "异常", "account_status_desc": f"重建 session 失败: {e2}"}
                        }
    
                # 代理异常识别
                if "Connection to Telegram failed" in err_msg:
                    return {
                        "status": False,
                        "message": f"代理异常: {err_msg}",
                        "data": {"account_status": "代理异常", "account_status_desc": f"代理异常: {err_msg}"}
                    }
    
                # 其他异常
                return {
                    "status": False,
                    "message": f"登录失败: {err_msg}",
                    "data": {"account_status": "异常", "account_status_desc": f"登录失败: {err_msg}"}
                }
                
    
    async def _start_monitor_only(self, client):
        """仅启动监听器"""
        #return True
        logger.info(f"监听器已启动: {self.account_id}")
        try:
            # 停旧监听
            if self.monitor and getattr(self.monitor, 'is_monitoring', False):
                try:
                    await self.monitor.stop()
                    logger.info(f"已停止旧监听器: {self.account_id}")
                except Exception as e:
                    logger.warning(f"停止旧监听器失败: {str(e)}")
        
        except Exception as e:
            logger.warning(f"检查旧监听器失败: {str(e)}")
        
        try:
            # 创建新的监听器
            self.monitor = TelegramMonitor(client, self.account_id, self.db, self.cache, self.tdata_path, io_lock=self._client_io_lock, conn_lock=self._client_conn_lock)
            
            # 添加默认事件处理器
            async def handle_new_message(data):
                
                logger.info(f"[新消息] 聊天: {data['message_data']['chat_id']}, 发送者: {data['message_data']['sender_id']}")
                pass
            async def handle_chat_update(data):
                pass
                #logger.info(f"[聊天更新] 聊天: {data}")
            
            self.monitor.add_handler('new_message', handle_new_message)
            self.monitor.add_handler('chat_update', handle_chat_update)
            # 启动监听
            await self.monitor.start()
            logger.info(f"监听器已启动: {self.account_id}")
            return True
            
        except asyncio.CancelledError:
            logger.info(f"监听任务被取消: {self.account_id}")
            if self.monitor:
                try:
                    await self.monitor.stop()
                except:
                    pass
            raise    
        except Exception as e:
            logger.error(f"启动监听器失败: {str(e)}")
            # 可以在这里添加重试逻辑
            return await self._retry_start_monitor(client)
    
    
    async def _retry_start_monitor(self, client, max_retries=3):
        """重试启动监听器"""
        for attempt in range(max_retries):
            try:
                if attempt > 0:
                    wait_time = 2 ** attempt  # 指数退避
                    logger.info(f"等待{wait_time}秒后重试启动监听器 (第{attempt+1}次): {self.account_id}")
                    await asyncio.sleep(wait_time)
                
                # 清理旧的monitor实例
                if hasattr(self, 'monitor') and self.monitor:
                    try:
                        if getattr(self.monitor, 'is_monitoring', False):
                            await self.monitor.stop()
                    except Exception:
                        pass
                
                # 创建新的监听器
                self.monitor = TelegramMonitor(client, self.account_id, self.db, self.cache, self.tdata_path, io_lock=self._client_io_lock, conn_lock=self._client_conn_lock)
                await self.monitor.start()
                
                logger.info(f"监听器重试启动成功 (第{attempt+1}次尝试): {self.account_id}")
                return True
                
            except Exception as e:
                logger.error(f"监听器重试启动失败 (第{attempt+1}次尝试): {str(e)}")
                # 启动失败时确保停止监听器（清理心跳等）
                if self.monitor:
                    try:
                        await self.monitor.stop()
                    except:
                        pass
        
        logger.error(f"监听器启动失败，已达到最大重试次数: {self.account_id}")
        return False 
                
    '''
    async def set_offline(self):
        """断开连接并清理缓存"""
        async with self._lock:
            # 停止监听任务
            if self._monitor_bg_task and not self._monitor_bg_task.done():
                self._monitor_bg_task.cancel()
                try:
                    await self._monitor_bg_task
                except asyncio.CancelledError:
                    pass
            # 停止监听
            if self.monitor and self.monitor.is_monitoring:
                await self.monitor.stop()

            self.session_cache["monitor_started"] = False
            # 断开连接
            client = self.session_cache.get("client")
            if client and client.is_connected():
                try:
                    #await client.send(UpdateStatusRequest(offline=True))
                    await client.disconnect()
                    
                except Exception as e:
                    logger.info(f"断开连接警告: {e}")

            self.session_cache["client"] = None
            # 重置状态
            self.session_cache["is_connected"] = False
            
            return {
                "status": True,
                "message": "已下线",
                "data": {"account_status": '退出',"account_status_desc":"已退出"}
            }
    '''
    async def set_offline(self):
        """断开连接并清理所有资源"""
        async with self._lock:
            try:
                # 1. 停止监控后台任务
                if self._monitor_bg_task and not self._monitor_bg_task.done():
                    self._monitor_bg_task.cancel()
                    try:
                        await self._monitor_bg_task
                    except asyncio.CancelledError:
                        pass
                    self._monitor_bg_task = None  # 清理引用
                
                # 2. 停止监听器
                if self.monitor and hasattr(self.monitor, 'is_monitoring') and self.monitor.is_monitoring:
                    await self.monitor.stop()
                
                # 3. 断开网络连接
                client = self.session_cache.get("client")
                if client and hasattr(client, 'is_connected') and client.is_connected():
                    try:
                        # 如果需要发送离线状态通知
                        # await client.send(UpdateStatusRequest(offline=True))
                        async with self._client_conn_lock:
                            async with self._client_io_lock:
                                await client.disconnect()
                    except Exception as e:
                        logger.info(f"断开连接警告: {e}")
    
             
                                
                # 4. 重置所有状态标志
                self.session_cache.update({
                    "monitor_started": False,
                    "client": None,
                    "is_connected": False,
                })
                self._api_governor.clear()
                self._membership_cache.clear()
                
            
                
                return {
                    "status": True,
                    "message": "已下线",
                    "data": {
                        "account_status": '退出',
                        "account_status_desc": "已退出"
                    }
                }
                
            except Exception as e:
                logger.error(f"下线过程中发生错误: {e}", exc_info=True)
                return {
                    "status": False,
                    "message": f"下线过程中发生错误: {e}",
                    "data": {
                        "account_status": '异常',
                        "account_status_desc": "下线异常"
                    }
                }
            
    async def _get_entity_type(self, entity):
        """判断实体类型（普通群组/超级群组/频道）"""
        if isinstance(entity, User):
            return "user"
        elif  isinstance(entity, Channel):
            if entity.megagroup:
                return "megagroup"  # 超级群组
            else:
                return "channel"    # 频道
        elif isinstance(entity, Chat):
            return "chat"          # 普通群组
        return "unknown"           # 未知类型
        
    async def _get_input_peer(self, entity, entity_type):
        """根据实体类型获取对应的InputPeer"""
        if entity_type == "user":
            return InputPeerUser(entity.id, entity.access_hash)
        elif entity_type == "chat":
            return InputPeerChat(entity.id)
        elif entity_type in ["megagroup", "channel"]:
            return InputPeerChannel(entity.id, entity.access_hash)
        raise ValueError(f"不支持的实体类型: {entity_type}")
    # 定义一个账号状态处理的装饰器
    def handle_account_status(func):
        @functools.wraps(func)
        async def wrapper(self, *args, **kwargs):
            # 初始化结果结构
            result = {"status": False, "message": "", "data": {}}
            
            try:
                # 检查缓存连接
                client = self.session_cache.get("client")
                  
                if not client or not self.session_cache["is_connected"]:
                    # 尝试自动重连
                    retry_result = await self.set_online()
                    if not retry_result["status"]:
                        result["message"] = f"登录失败：请先调用上线。原因：{retry_result['message']}"
                        result["data"]["is_connected"] = False
                        result["data"]["account_status"] = "异常"
                        result["data"]["account_status_desc"] = "登录失败，先登录"
                        
                        await self._updateaccout('异常','登录失败，先登录')
                        
                        return result
                    client = self.session_cache["client"]
                
                try:
                    if not  client.is_connected():
                        result["message"] = "连接已失效，请重新调用上线"
                        result["data"]["is_connected"] = False
                        result["data"]["account_status"] = "异常"
                        result["data"]["account_status_desc"] = "登录失败，先登录"
                        await self._updateaccout('异常','登录失败，先登录')
                        return result
                except Exception as e:
                    result["message"] = f"连接校验失败: {str(e)}"
                    result["data"]["is_connected"] = False
                    result["data"]["account_status"] = "异常"
                    result["data"]["account_status_desc"] = "连接异常"
                    await self._updateaccout('异常','连接异常')
                    return result
                    
                #logger.info(f"[client] 状态:{client}")
                # 检查账号是否被注销
                try:
                    safe_kwargs = {k: v for k, v in kwargs.items() if k not in ["client", "current_user", "result"]}

                    current_user = await self._get_me_cached(client)

                    '''
                    frozen_check = await self.detect_frozen_by_bot_verification(client)
                    
                    if frozen_check.get("status") == "FROZEN":
                        result["data"]["account_status"] = "冻结"
                        result["data"]["account_status_desc"] = frozen_check.get("reason", "账号被冻结")
                        result["status"] = False
                        result["message"] = frozen_check.get("reason", "账号被冻结")
                        
                        await self._updateaccout(
                            '冻结', 
                            frozen_check.get("reason", "账号被冻结")
                        )
                        
                        try:
                            await client.disconnect()
                        except:
                            pass
                            
                        return result
                        
                    '''

                    if getattr(current_user, 'restricted', False):
                        restriction_reason = getattr(current_user, 'restriction_reason', [])
                        if restriction_reason:
                            reason_text = ', '.join(
                                [r.reason if hasattr(r, 'reason') else str(r) for r in restriction_reason]
                            ) or "未知原因"
                            result["data"]["account_status"] = "冻结"
                            result["data"]["account_status_desc"] = "账号受限，部分功能不可用"
                            result["data"]["reason"] = reason_text
                            result["status"] = False
                            result["message"] = f"账号被冻结，原因: {reason_text}"
                            await client.disconnect()
                            self.session_cache["is_connected"] = False
                            await self._updateaccout('冻结','账号受限，部分功能不可用')
                            return result
                        else:
                            result["data"]["account_status"] = "封号"
                            result["data"]["account_status_desc"] = "账号已被封禁"
                            result["status"] = False
                            result["message"] = "账号已被封禁"
                            await client.disconnect()
                            self.session_cache["is_connected"] = False
                            await self._updateaccout('封号','账号已被封禁')
                            return result

                    result = await func(
                        self,
                        *args,
                        client=client,
                        current_user=current_user,
                        result=result,
                        **safe_kwargs
                    )
                    
                except InputUserDeactivatedError:
                    # 明确识别账号被注销或删除的情况
                    result["data"]["account_status"] = "注销"
                    result["data"]["account_status_desc"] = "账号已被注销"
                    result["status"] = False
                    result["message"] = "账号已被注销"
                    await self._updateaccout('注销','账号已被注销')
                except Exception as e:
                    result["status"] = False
                    err_msg = str(e)

                    # === 新增错误分类 ===
                    # Telegram session key 被多 IP 使用
                    if "authorization key" in err_msg and "used under two different IP addresses" in err_msg:
                        await self._updateaccout('会话失效','session已在其他IP使用，请重新生成会话')
                        return {
                            "status": False,
                            "message": f"会话失效：{err_msg}",
                            "data": {"account_status": "会话失效", "account_status_desc": "session已在其他IP使用，请重新生成会话"}
                        }
                    elif "Connection to Telegram failed" in err_msg:
                   
                        result["data"]["account_status"] = "代理异常"
                        result["data"]["account_status_desc"] = "代理无法连接"
                        result["message"] = f"网络异常: {err_msg}"
                        await self._updateaccout('代理异常','代理无法连接')
                    else:
                    
                        result["data"]["account_status"] = "异常"
                        result["data"]["account_status_desc"] = f"获取用户信息失败: {err_msg}"
                        result["message"] = err_msg
                        await self._updateaccout('异常','获取用户信息失败')
    
            except Exception as e:
                result["status"] = False
                result["data"]["account_status"] = "异常"
                result["data"]["account_status_desc"] = "获取用户信息失败"
                result["message"] = f"获取失败: {str(e)}"
                await self._updateaccout('异常','获取用户信息失败')
            '''
            finally:
                # 确保断开连接
                if hasattr(self, 'client') and self.client and self.client.is_connected():
                    try:
                        await self.client.disconnect()
                    except:
                        pass
            '''
            
            return result
        return wrapper
        
    # 更新数据库状态    
    async def _updateaccout(self, account_status, account_status_desc):
        
        try:
            session_path = self.tdata_path
            if session_path:
                await self.db.update_mtuser_status_by_session(
                    session_path, 
                    0,
                    account_status, 
                    account_status_desc,
                )
        except Exception as db_e:
            logger.error(f"Failed to update DB status: {db_e}")

     
    
    
    #获取    获取所有群组/频道列表，包含头像URL和未读消息数#
    @handle_account_status
    async def get_groups(self, client=None, current_user=None, result=None, force_refresh=False):
        """
        获取所有群组/频道列表（优化版）
        :param force_refresh: 是否强制刷新（跳过缓存）
        """
        start_time = time.time()
        # 1. 构建缓存Key（唯一标识当前账号的群组缓存）
        cache_key = f"groups_{current_user.id}_{self.tdata_path}"
        
        # 2. 检查缓存（非强制刷新时）
        if not force_refresh and cache_key in GROUP_CACHE:
            cache_data = GROUP_CACHE[cache_key]
            if time.time() - cache_data["timestamp"] < CACHE_EXPIRE:
                cache_data["data"]["message"] += "（来自缓存）"
                cache_data["data"]["data"]["load_time"] = f"{int((time.time() - start_time)*1000)}ms"
                return cache_data["data"]

        # 3. 初始化结果结构
        result = {
            "status": False, 
            "message": "", 
            "data": {
                "groups": [],
                "load_time": "0ms"  # 新增：加载耗时统计
            }
        }
        try:
            path_parts = os.path.normpath(self.tdata_path).split(os.sep)
            uploads_index = path_parts.index("uploads")
            # 拼接基础路径: .../public/uploads/
            base_uploads_path = os.sep.join(path_parts[:uploads_index + 1])
            avatar_base_dir = os.path.join(base_uploads_path, "groupimg")
            
            # 确保目录存在并设置权限
            os.makedirs(avatar_base_dir, exist_ok=True)
            os.chmod(avatar_base_dir, 0o755)
            
            # 获取绝对路径的规范形式
            avatar_base_dir = os.path.abspath(avatar_base_dir)
            logger.info(f"群组头像存储目录：{avatar_base_dir}")
            
            groups = []
            # 4. 遍历对话（仅筛选群组/频道，减少冗余处理）
            async for dialog in client.iter_dialogs():
                if dialog.is_group or dialog.is_channel:
                    # 4.1 从dialog直接提取信息（无需额外调用get_entity）
                    entity = dialog.entity
                    group_id = dialog.id
                    avatar_filename = f"group_{group_id}.jpg"
                    avatar_abs_path = os.path.join(avatar_base_dir, avatar_filename)
                    
                    avatar_url = None
                    # 只有当文件不存在或强制刷新时才下载
                    if (not os.path.exists(avatar_abs_path)) or force_refresh:
                        if hasattr(entity, 'photo') and entity.photo:
                            try:
                                # 下载小尺寸头像
                                photo_bytes = await client.download_profile_photo(
                                    entity, 
                                    file=bytes,
                                    download_big=False
                                )
                                if photo_bytes:
                                    # 保存到绝对路径
                                    with open(avatar_abs_path, "wb") as f:
                                        f.write(photo_bytes)
                                    os.chmod(avatar_abs_path, 0o644)
                                    avatar_url = avatar_abs_path
                                    logger.info(f"已保存头像: {avatar_abs_path}")
                            except Exception as e:
                                logger.warning(f"下载群组{group_id}头像失败: {str(e)}")
                                avatar_url = None
                    else:
                        # 直接使用已存在的本地文件
                        avatar_url = avatar_abs_path
                        logger.debug(f"使用本地头像: {avatar_abs_path}")
                        
                    web_root_path = os.path.join(os.path.dirname(base_uploads_path), "")  # 到public目录
                    avatar_web_path = os.path.relpath(avatar_abs_path, web_root_path).replace(os.sep, '/')
                    avatar_web_path = f"/{avatar_web_path}"  # 确保以/开头    
                    
                    # 4.2 精简字段：只返回前端必需的信息
                    group_info = {
                        "id": dialog.id,
                        "title": dialog.title,
                        "type": "channel" if dialog.is_channel else "group",
                        "unread_count": dialog.unread_count,
                        "last_msg": "",
                        "member_count": entity.participants_count if hasattr(entity, 'participants_count') else 0,
                        "can_click": True,
                        "avatar_web_path": avatar_web_path,
                        "is_megagroup": entity.megagroup if isinstance(entity, Channel) else False  # 补充超级群组标识
                    }
                    
                    # 4.3 处理最后一条消息（限制长度，避免大文本传输）
                    if dialog.message:
                        if dialog.message.text:
                            group_info["last_msg"] = dialog.message.text[:50]  # 只保留前50字符
                        else:
                            group_info["last_msg"] = "[非文本消息]"
                    
                    groups.append(group_info)
            
            # 5. 计算耗时
            load_time = int((time.time() - start_time) * 1000)
            # 6. 填充结果
            result["status"] = True
            result["message"] = f"成功获取{len(groups)}个群组/频道（耗时{load_time}ms）"
            result["data"]["groups"] = groups
            result["data"]["load_time"] = f"{load_time}ms"
            
            # 7. 更新缓存
            GROUP_CACHE[cache_key] = {
                "timestamp": time.time(),
                "data": result
            }
            
        except Exception as e:
            result["message"] = f"获取群组失败: {str(e)}"
        
        return result
        
    #屏蔽并删除用户（机器人直接删除），如果是群组则退出群组#   
    @handle_account_status
    async def block_user(self, user_id, client=None, current_user=None,result=None):
        """屏蔽并删除用户/机器人，或者退出群组"""
        result = {"status": False, "message": ""}
        try:
            # 确保 user_id 为整数
            try:
                user_id_int = int(user_id)
            except ValueError:
                result["message"] = f"无效的ID: {user_id}"
                return result
            
            # 判断是群组还是用户
            if user_id_int < 0:
                # 调用群组处理函数
                return await self._process_group(user_id_int, client, "block")
            else:
                # 用户处理逻辑
                return await self._process_user(user_id_int, client, "block")
                
        except Exception as e:
            result["message"] = f"操作失败: {str(e)}"
        return result

    #删除用户或机器人并清理聊天记录，如果是群组则退出群组#    
    @handle_account_status
    async def deleteUser(self, user_id, client=None, current_user=None,result=None):
        """删除用户/机器人并清理聊天记录，如果是群组则退出群组"""
        result = {"status": False, "message": ""}
        try:
            # 确保 user_id 为整数
            try:
                user_id_int = int(user_id)
            except ValueError:
                result["message"] = f"无效的ID: {user_id}"
                return result
            
            # 判断是群组还是用户
            if user_id_int < 0:
                # 调用群组处理函数
                return await self._process_group(user_id_int, client, "delete")
            else:
                # 用户处理逻辑
                return await self._process_user(user_id_int, client, "delete")
                
        except Exception as e:
            result["message"] = f"操作失败: {str(e)}"
        return result

    # 群组处理函数
    async def _process_group(self, group_id, client, action_type="block"):
        """处理群组操作（退出群组）
        
        Args:
            group_id: 群组ID（负数）
            client: Telegram客户端
            action_type: 操作类型，"block" 或 "delete"
        """
        result = {"status": False, "message": ""}
        
        try:
            # 获取群组实体
            entity = await self._get_entity_cached(client, group_id)
            
            # 判断实体类型
            from telethon.tl.types import Chat, Channel
            
            if isinstance(entity, (Chat, Channel)):
                # 构造群组peer
                if isinstance(entity, Channel):
                    # 超级群组/频道
                    group_peer = InputPeerChannel(channel_id=entity.id, access_hash=entity.access_hash)
                    is_channel = True
                else:
                    # 普通群组
                    group_peer = InputPeerChat(chat_id=entity.id)
                    is_channel = False
                
                # 根据操作类型执行不同的处理
                if action_type == "block":
                    # block_user 的处理逻辑：主要关注退出群组
                    try:
                        if is_channel:
                            # 对于频道/超级群组，使用LeaveChannelRequest
                            await client(LeaveChannelRequest(channel=group_peer))
                            result["message"] = f"已成功退出群组/频道 {group_id}"
                        else:
                            # 对于普通群组，直接删除对话框
                            await client.delete_dialog(group_peer)
                            result["message"] = f"已成功退出群组 {group_id}"
                        
                        result["status"] = True
                        
                    except FloodWaitError as e:
                        result["message"] = f"操作过于频繁，请等待 {e.seconds} 秒后再试"
                        return result
                    except Exception as e:
                        # 尝试其他方法
                        try:
                            if is_channel:
                                await client(DeleteChannelRequest(channel=group_peer))
                            else:
                                await client.delete_dialog(group_peer)
                            result["status"] = True
                            result["message"] = f"已成功退出群组 {group_id}"
                        except Exception as e2:
                            result["message"] = f"退出群组失败: {str(e)}"
                            return result
                            
                else:  # action_type == "delete"
                    # deleteUser 的处理逻辑：主要关注清理聊天记录
                    try:
                        # 先删除对话框（聊天记录）
                        await client.delete_dialog(group_peer)
                        
                        # 如果是频道/超级群组，还需要离开
                        if is_channel:
                            try:
                                await client(LeaveChannelRequest(channel=group_peer))
                            except Exception:
                                # 忽略离开频道的错误，主要目的是删除对话框
                                pass
                        
                        result["status"] = True
                        result["message"] = f"已成功退出并清理群组 {group_id} 的聊天记录"
                        
                    except Exception as e:
                        result["message"] = f"退出群组失败: {str(e)}"
                        return result
                        
            else:
                result["message"] = f"ID {group_id} 不是有效的群组实体"
                return result
                
        except PeerIdInvalidError:
            if action_type == "delete":
                result["message"] = f"找不到该群组（可能没有任何对话），ID: {group_id}"
            else:
                result["message"] = f"找不到该群组，ID: {group_id}"
            return result
        except InputUserDeactivatedError:
            result["message"] = f"群组 {group_id} 已不存在"
            return result
        except Exception as e:
            result["message"] = f"获取群组信息失败: {str(e)}"
            return result
        
        return result

    # 用户处理函数
    async def _process_user(self, user_id, client, action_type="block"):
        """处理用户操作（屏蔽/删除用户）
        
        Args:
            user_id: 用户ID（正数）
            client: Telegram客户端
            action_type: 操作类型，"block" 或 "delete"
        """
        result = {"status": False, "message": ""}
        
        try:
            # 获取用户实体
            from telethon.tl.types import User
            entity = await self._get_entity_cached(client, user_id)
            
            if not isinstance(entity, User):
                result["message"] = f"ID {user_id} 不是有效的用户实体"
                return result
                
            if getattr(entity, "deleted", False):
                result["message"] = f"用户 {user_id} 已注销"
                return result
                
        except (PeerIdInvalidError, InputUserDeactivatedError):
            result["message"] = f"找不到该用户或已注销，ID: {user_id}"
            return result
        except Exception as e:
            result["message"] = f"获取用户信息失败: {str(e)}"
            return result

        # 构造 InputPeerUser
        try:
            user_peer = InputPeerUser(user_id=entity.id, access_hash=entity.access_hash)
        except AttributeError as e:
            result["message"] = f"构造用户实体失败: {str(e)}"
            return result
        
        # 判断是否是机器人
        is_bot = getattr(entity, "bot", False)
        
        # 根据操作类型执行不同的处理
        if action_type == "delete":
            # deleteUser 的处理逻辑
            try:
                await client(DeleteContactsRequest(id=[user_peer]))
            except Exception as e:
                result["message"] = f"删除联系人失败: {str(e)}"
                return result

            try:
                await client.delete_dialog(user_peer)
            except Exception as e:
                result["message"] = f"已删除联系人，但清理对话失败: {str(e)}"
                result["status"] = True
                return result

            result["status"] = True
            if is_bot:
                result["message"] = f"已删除机器人 {user_id} 并清理聊天记录"
            else:
                result["message"] = f"已删除用户 {user_id} 并清理聊天记录"
                
        else:  # action_type == "block"
            # block_user 的处理逻辑
            if is_bot:
                # 机器人直接删除
                try:
                    await client(DeleteContactsRequest(id=[user_peer]))
                except Exception as e:
                    result["message"] = f"删除机器人失败: {str(e)}"
                    return result

                try:
                    await client.delete_dialog(user_peer)
                except Exception as e:
                    result["message"] = f"已删除机器人，但清理聊天对话失败: {str(e)}"
                    result["status"] = True
                    return result

                result["status"] = True
                result["message"] = f"已删除机器人 {user_id} 并清理聊天记录"
            else:
                # 普通用户 → 屏蔽
                try:
                    await client(BlockRequest(id=user_peer))
                except FloodWaitError as e:
                    result["message"] = f"操作过于频繁，请等待 {e.seconds} 秒后再试"
                    return result
                except Exception as e:
                    result["message"] = f"屏蔽操作失败: {str(e)}"
                    return result

                # 删除联系人
                try:
                    await client(DeleteContactsRequest(id=[user_peer]))
                except Exception as e:
                    result["message"] = f"屏蔽成功，但删除联系人失败: {str(e)}"
                    result["status"] = True
                    return result

                # 删除聊天记录
                try:
                    await client.delete_dialog(user_peer)
                except Exception as e:
                    result["message"] = f"屏蔽并删除联系人成功，但清理聊天对话失败: {str(e)}"
                    result["status"] = True
                    return result

                result["status"] = True
                result["message"] = f"已成功屏蔽并删除用户 {user_id} 并清理聊天记录"
        
        return result
    #删除聊天记录#   
    @handle_account_status
    async def delete_chat_history(self, target_id, is_private=True, client=None, current_user=None,result=None):
        """删除聊天记录"""
        result = {"status": False, "message": ""}
        try:
           
            # 处理用户ID - 确保是整数类型
            try:
                user_id = int(target_id)
                try_ids = [user_id, abs(user_id)]  # 尝试可能的ID形式
                
                entity = None
                for uid in try_ids:
                    try:
                        entity = await self._get_entity_cached(client, uid)
                        if hasattr(entity, 'user'):  # 确认是用户实体
                            break
                    except Exception as e:
                        continue
    
                if not entity:
                    result["message"] = f"无法找到用户ID为 {target_id} 的用户"
                  
                    return result
    
                # 批量获取并删除消息
                messages = []
                async for msg in client.iter_messages(entity):
                    messages.append(msg.id)
                    
                    # 每100条消息一批进行删除（Telegram API限制）
                    if len(messages) >= 100:
                        # 使用正确的函数调用方式
                        await client(DeleteMessagesRequest(
                            id=messages,
                            revoke=True  # 撤回消息（对双方都生效）
                        ))
                        messages = []
                
                # 删除剩余的消息
                if messages:
                    await client(DeleteMessagesRequest(
                        id=messages,
                        revoke=True
                    ))
                
                result["status"] = True
                result["message"] = f"已删除与用户ID {target_id} 的所有聊天记录"
                
            except ValueError:
                result["message"] = f"无效的用户ID格式: {target_id}，必须是整数"
            except Exception as e:
                result["message"] = f"删除记录失败: {str(e)}"
            
                    
        except Exception as e:
            result["message"] = f"操作失败: {str(e)}"
        return result
    #获取所有私聊对象（包括未保存到通讯录的临时会话）#     
    @handle_account_status    
    async def get_contacts(self, client=None, current_user=None,result=None):
        """
        获取所有私聊对象（包括未保存到通讯录的临时会话）
        :param filter_unread: 是否只返回有未读消息的对象（默认False）
        """
        result = {
            "status": False, 
            "message": "", 
            "data": {
                "private_chats": [],  # 所有私聊对象
                "unread_chats": [],   # 仅包含有未读消息的私聊对象
                "total_unread": 0     # 私聊未读消息总数
            }
        }
        filter_unread=False
        try:
           
            
            private_chats = []
            unread_chats = []
            total_unread = 0
            
            # 遍历所有对话，筛选出私聊类型
            async for dialog in client.iter_dialogs():
                # 判断是否为私聊（排除群组、频道）
                if dialog.is_user and not dialog.is_group and not dialog.is_channel:
                    # 获取用户详细信息
                    user = await self._get_entity_cached(client, dialog.id)
                    # 使用user实体获取头像（符合逻辑的处理方式）
                    avatar_url = None
                    '''
                    try:
                        if user.photo:  # 先检查是否有头像
                            photo_bytes = await client.download_profile_photo(user, file=bytes)
                            if photo_bytes:
                                import base64
                                avatar_url = f"data:image/jpeg;base64,{base64.b64encode(photo_bytes).decode()}"
                    except:
                        avatar_url = None     
                    '''    
                    chat_info = {
                        "id": user.id,
                        "username": user.username or "",
                        "first_name": user.first_name or "",
                        "last_name": user.last_name or "",
                        "title": dialog.title,
                        "unread_count": dialog.unread_count,  # 未读消息数
                        "has_unread": dialog.unread_count > 0,  # 新增：是否有未读
                        "avatar_url": avatar_url,  # 头像URL
                        "is_contact": user.contact
                    }
                    
                    # 累计未读总数
                    if dialog.unread_count > 0:
                        total_unread += dialog.unread_count
                        unread_chats.append(chat_info)
                    
                    # 根据筛选条件决定是否添加到总列表
                    if not filter_unread or (filter_unread and dialog.unread_count > 0):
                        private_chats.append(chat_info)
            
            result["status"] = True
            result["data"]["private_chats"] = private_chats
            result["data"]["unread_chats"] = unread_chats
            result["data"]["total_unread"] = total_unread
            result["message"] = (
                f"获取私聊对象成功，共 {len(private_chats)} 个，"
                f"其中 {len(unread_chats)} 个有未读消息，总计 {total_unread} 条未读"
            )
            
        except Exception as e:
            result["message"] = f"获取私聊对象失败: {str(e)}"
        return result
        
    #获取指定聊天的新消息#   
    @handle_account_status
    async def get_new_messages(self, target_id=None,  last_msg_id=0, timeout=3, client=None, current_user=None,result=None):
        """获取指定聊天的新消息"""
        result = {"status": False, "message": "", "data": {"messages": []}}
        
        try:
            
            # 转换目标ID格式
            target_id = int(target_id)
            if target_id > 0:
                target_id = -target_id  # 群组ID通常为负数
            
            # 获取实体
            entity = await self._get_entity_cached(client, target_id)
            
            # 获取新消息（ID大于last_msg_id的消息）
            messages = []
            async for msg in client.iter_messages(entity, min_id=last_msg_id, limit=None):
                # 注意：使用 msg.out 而非 msg.outgoing
                messages.append({
                    "id": msg.id,
                    "text": msg.text,
                    "date": msg.date.isoformat(),
                    "sender_id": msg.sender_id,
                    "is_outgoing": msg.out,  # 修正属性名
                    "media": "存在" if msg.media else "不存在"
                })
            
            
            result["status"] = True
            result["message"] = f"成功获取 {len(messages)} 条新消息"
            result["data"]["messages"] = messages
            
        except Exception as e:
            result["message"] = f"获取消息失败: {str(e)}"
            
                
        return result   
    # 在TelegramAccountHandler类中添加
    
    # 在TelegramAccountHandler类中添加
    @handle_account_status
    async def send_messages(self, group_ids, message_type, text=None, forward_id=None, media_paths=None, delay=1, feedback_type=None,first_msg_id=0, message_unique_id=None,  client=None, current_user=None,result=None):
        """
        发送消息到指定群组（适配普通群组/超级群组/频道）
        :param group_ids: 群组ID列表
        :param message_type: 消息类型 text/forward/image/voice/image_text
        :param text: 文本内容（text类型需要）
        :param forward_id: 转发消息ID（forward类型需要）
        :param media_paths: 媒体文件路径列表（image/voice类型需要）
        :param delay: 发送延迟（秒）
        :param feedback_type: 回复类型
        :param message_unique_id: 消息唯一ID，用于去重
        :return: 发送结果
        """
        if "success" not in result["data"]:
            result["data"]["success"] = []
        if "failed" not in result["data"]:
            result["data"]["failed"] = []
        if "debug" not in result["data"]:
            result["data"]["debug"] = []
        if "warning" not in result["data"]:
            result["data"]["warning"] = ""
        # 幂等性检查
        if message_unique_id:
            try:
                # 使用 Redis 检查是否已处理
                cache_key = f"msg_idempotency:{message_unique_id}"
                cached_result = await self.cache.get(cache_key)
                if cached_result:
                    # 如果状态是 processing，说明正在处理中
                    if cached_result.get("__status") == "processing":
                         logger.info(f"[Idempotency] 检测到请求 {message_unique_id} 正在处理中，拒绝重复执行")
                         # 返回一个特殊状态，或者直接返回空结果（PHP会重试，但不会导致双重发送）
                         # 如果PHP遇到失败会重试，我们这里返回失败即可
                         # 只要不执行 send 逻辑，就不会重复发送
                         return {
                             "status": False, 
                             "message": "Duplicate request: processing in progress", 
                             "data": {"success": [], "failed": [], "debug": ["Request locked by processing status"]}
                         }

                    logger.info(f"[Idempotency] 检测到重复请求 {message_unique_id}，返回缓存结果")
                    if isinstance(cached_result, dict) and cached_result.get("status"):
                        return cached_result
                
                # 设置处理中状态 (TTL 600秒，防止死锁)
                await self.cache.set(cache_key, {"__status": "processing"}, ttl=600)
                
            except Exception as e:
                logger.warning(f"[Idempotency] 检查缓存失败: {e}")
        try:
            logger.info(
                f"[send_messages] 开始执行，群组列表: {group_ids}, 消息类型: {message_type}, "
                f"当前账号ID: {current_user.id if current_user else '未知'}"
            )
            # 处理group_ids输入格式，支持逗号分隔字符串或列表
            if isinstance(group_ids, str):
                # 分割逗号并去除空格
                original_group_ids = group_ids 
                group_ids = [id.strip() for id in group_ids.split(',') if id.strip()]
                logger.debug(f"[send_messages] 字符串群组ID转换为列表: 原始='{original_group_ids}', 转换后={group_ids}")
            
            # 去重处理
            unique_group_ids = list(set(group_ids))
            if len(unique_group_ids) < len(group_ids):
                result["data"]["warning"] = f"检测到重复群组ID，已自动去重，原{len(group_ids)}个，去重后{len(unique_group_ids)}个"
                group_ids = unique_group_ids
                
            # 获取当前账号信息
            if not current_user:
                raise Exception("未获取到当前账号信息（current_user为空）")
            current_user_id = current_user.id
            
            for group_identifier in group_ids:
                group_id = None
                is_member=False
                try:
                    # 确保group_identifier是字符串类型，便于处理
                    group_str = str(group_identifier).strip()
                    # 清除所有可能的转义字符和引号
                    group_str_clean = group_str.replace('\\', '').replace('"', '').replace("'", "")
                    is_telegram_link = False
                    
                    # 针对命令行传递的链接格式进行增强识别
                    # 检查是否包含t.me或telegram.me，无论位置和协议
                    if 't.me' in group_str_clean or 'telegram.me' in group_str_clean:
                        is_telegram_link = True
                    # 检查是否是纯数字ID
                    is_numeric_id = group_str_clean.lstrip('-').isdigit()
                    
                    # 调试信息
                    result["data"]["debug"].append(
                        f"处理标识符: {group_identifier}, 清理后: {group_str_clean}, "
                        f"识别为链接: {is_telegram_link}, 识别为数字ID: {is_numeric_id}"
                    )
                    normalized_link = None
                
                    # 最宽松的链接判断：只要包含t.me就视为链接
                    if 't.me' in group_str_clean:
                        is_telegram_link = True
                        # 标准化链接
                        if not group_str_clean.startswith(('http://', 'https://')):
                            normalized_link = f"https://{group_str_clean}"
                        else:
                            normalized_link = group_str_clean
                    # 处理群链接
                    if is_telegram_link:
                        try:
                            # 私密邀请链接 (+号开头)
                            if "+" in normalized_link:
                                hash_part = normalized_link.split("+")[-1]
                                try:
                                    # 尝试直接获取实体（账号已加入的情况）
                                    entity = await self._get_entity_cached(client, normalized_link)
                                except ValueError:
                                    # 未加入，使用 ImportChatInviteRequest 加入
                                    await self._wait_for_api_slot("import_chat_invite")
                                    updates = await client(ImportChatInviteRequest(hash_part))
                                    if updates.chats and len(updates.chats) > 0:
                                        entity = updates.chats[0]
                                    else:
                                        raise ValueError("加入私密群组后无法获取实体")
                            
                                group_id = entity.id
                                entity_type = await self._get_entity_type(entity)
                                is_member = True
                              
                            else:
                            # 公开群组/频道 尝试获取实体
                                entity = await self._get_entity_cached(client, normalized_link)
                                entity_type = await self._get_entity_type(entity)
                                is_member = await self._check_group_membership(entity, current_user_id, entity_type,client)
                                
                                # 如果不是成员，加入群组
                                if not is_member:
                                    # 修复：使用正确的方法加入群组（根据TelegramClient库版本选择）
                                    await self._wait_for_api_slot("join_channel")
                                    await client(JoinChannelRequest(entity))
                                    
                                    result["data"]["debug"].append(f"成功加入群组: {normalized_link}")
                                    await asyncio.sleep(5)  # 延长等待时间，确保加入成功
                                    
                                    # 重新获取实体以获取最新信息
                                    entity = await self._get_entity_cached(client, normalized_link, force_refresh=True)
                                    # 从实体中获取群组ID
                                    group_id = entity.id
                                    
                                    result["data"]["debug"].append(f"加入后获取群组ID: {group_id}")
                                    
                                    # 重新检查成员身份
                                    is_member = await self._check_group_membership(entity, current_user_id, await self._get_entity_type(entity),client)
                                    if not is_member:
                                        raise PermissionError(f"加入群组 {normalized_link} 后仍无法获取成员身份")
                                else:
                                    # 如果已是成员，直接获取群组ID
                                    group_id = entity.id
                                    result["data"]["debug"].append(f"已是群组成员，群组ID: {group_id}")
                                
                        except Exception as e:
                            error_msg = f"处理链接 {normalized_link} 时出错: {str(e)}"
                            result["data"]["debug"].append(error_msg)
                            raise PermissionError(error_msg)
                    
                    # 处理ID格式
                    elif is_numeric_id:
                        entity = None
                        if group_str_clean.lstrip('-').isdigit():
                            group_id = int(group_str_clean)
                            
                            # 尝试获取实体
                            try:
                                # 直接尝试获取（适用于用户ID和群组ID）
                                entity = await self._get_entity_cached(client, group_id)
                                entity_type = await self._get_entity_type(entity)
                                target_id = entity.id
                                logger.info(
                                    f"通过数字ID获取成功: {group_id} -> 类型: {entity_type}, ID: {target_id}"
                                )
                                
                                result["data"]["debug"].append(
                                    f"通过数字ID获取成功: {group_id} -> 类型: {entity_type}, ID: {target_id}"
                                )
                                
                            except Exception as e:
                                result["data"]["debug"].append(f"直接获取数字ID失败，尝试其他方式: {str(e)}")
                                
                                # 可能是群组ID需要转换格式
                                if group_id > 0:
                                    # 尝试转换为可能的群组ID格式
                                    possible_ids = [
                                        -group_id,  # 普通群组格式
                                        int(f"-100{group_id}"),  # 频道格式
                                    ]
                                    
                                    for pid in possible_ids:
                                        try:
                                            entity = await self._get_entity_cached(client, pid)
                                            entity_type = await self._get_entity_type(entity)
                                            target_id = entity.id
                                            result["data"]["debug"].append(f"转换ID成功: {group_id} -> {pid} (类型: {entity_type})")
                                            break
                                        except:
                                            continue
                                
                                if not entity:
                                    # 如果所有尝试都失败，可能是用户名
                                    try:
                                        chat_username = await self.db.get_chat_username(group_str_clean)
                                        entity = await self._get_entity_cached(client, chat_username)
                                        entity_type = await self._get_entity_type(entity)
                                        target_id = entity.id
                                        result["data"]["debug"].append(f"作为用户名获取成功: {group_str_clean}")
                                    except Exception as username_e:
                                        raise ValueError(f"无法识别目标标识符: {group_identifier} (既不是有效的ID也不是用户名)")
                        
                        else:
                            # 按用户名处理
                            try:
                                entity = await self._get_entity_cached(client, group_str_clean)
                                entity_type = await self._get_entity_type(entity)
                                target_id = entity.id
                                result["data"]["debug"].append(f"作为用户名获取成功: {group_str_clean}")
                            except Exception as e:
                                raise ValueError(f"无法识别目标标识符: {group_identifier}")
                
                    else:
                        raise ValueError(f"无效的群组标识符: {group_identifier}，既不是有效的ID也不是链接")
                    
                    
                
                    # 获取实体类型和输入对等体
                    entity_type = await self._get_entity_type(entity)
                    input_peer = await self._get_input_peer(entity, entity_type)
                    # 检查成员身份
                    if entity_type == "user":
                        is_member = True   # 私聊不需要检查
                    else:
                        is_member = await self._check_group_membership(entity, current_user_id, entity_type,client)
                        if not is_member:
                            raise PermissionError(f"账号不在该{entity_type}中，无法发送消息")

                    
                    
                    # 检查发送权限
                    if entity_type == "channel" and not entity.broadcast:
                        await self._wait_for_api_slot("get_permissions", min_interval=0.5)
                        permissions = await client.get_permissions(entity, current_user)
                        if not permissions.send_messages:
                            raise PermissionError("没有在频道发送消息的权限")

                    # 处理回复消息ID
                    reply_to_msg_id = None
                    if feedback_type == "forward" and first_msg_id > 0:
                        # 先验证first_msg_id是否在当前聊天中有效
                        try:
                            # 尝试获取该消息
                            message = await client.get_messages(entity, ids=first_msg_id)
                            if message:
                                reply_to_msg_id = first_msg_id
                        except: 
                            pass      
    
                    # 发送消息
                    message_id = None
                    logger.info(f"[entity_type] 类型 {entity_type} ")
                    # 根据消息类型和群组类型发送消息
                    if message_type == "text":
                        max_retries = 3
                        retry_count = 0
                        while retry_count < max_retries:
                            try:
                                async with self.send_lock:
                                    await self._wait_for_api_slot("send_message")
                                    sent_msg = await client.send_message(
                                        input_peer, 
                                        text,
                                        reply_to=reply_to_msg_id
                                    )
                                message_id = sent_msg.id
                                break
                            except FloodWaitError as e:
                                retry_count += 1
                                wait_time = e.seconds + random.randint(5, 10)  # 额外添加随机延迟
                                logger.warning(f"发送消息遇到洪水控制，等待 {wait_time} 秒后重试 (第 {retry_count}/{max_retries} 次)")
                                await asyncio.sleep(wait_time)
                                if retry_count == max_retries:
                                    raise Exception(f"发送消息失败，已达到最大重试次数: {str(e)}")
                            except Exception as e:
                                if retry_count == max_retries - 1:
                                    raise
                                retry_count += 1
                                wait_time = random.randint(10, 20)
                                logger.warning(f"发送消息失败，等待 {wait_time} 秒后重试 (第 {retry_count}/{max_retries} 次): {str(e)}")
                                await asyncio.sleep(wait_time)

                    elif message_type in ["image", "image_text"]:
                        # 验证媒体文件
                        valid_files = []
                        for i, file_path in enumerate(media_paths):
                            if isinstance(file_path, str):
                                if os.path.exists(file_path):
                                    file_size = os.path.getsize(file_path)
                                    valid_files.append(file_path)
                                    result["data"]["debug"].append(f"文件{i+1}有效: {file_path} ({file_size}字节)")
                                else:
                                    result["data"]["debug"].append(f"文件{i+1}无效: {file_path} (不存在)")
                        
                        if not valid_files:
                            raise Exception("没有有效的媒体文件")
                        
                        # 设置caption
                        caption_text = text if text and str(text).strip() else None
                        
                        max_retries = 3
                        retry_count = 0
                        while retry_count < max_retries:
                            try:
                                if len(valid_files) > 1:
                                    result["data"]["debug"].append(f"批量发送 {len(valid_files)} 个文件")
                                    async with self.send_lock:
                                        await self._wait_for_api_slot("send_file")
                                        sent_msgs = await client.send_file(
                                            input_peer,
                                            valid_files,  # 传递文件列表
                                            caption=caption_text,
                                            reply_to=reply_to_msg_id
                                        )
                                    if isinstance(sent_msgs, list):
                                        message_id = sent_msgs[0].id if sent_msgs else None
                                    else:
                                        message_id = sent_msgs.id if hasattr(sent_msgs, 'id') else None
                                else:
                                    # 单个文件
                                    result["data"]["debug"].append(f"发送单个文件: {valid_files[0]}")
                                    async with self.send_lock:
                                        await self._wait_for_api_slot("send_file")
                                        sent_msg = await client.send_file(
                                            input_peer,
                                            valid_files[0],
                                            caption=caption_text,
                                            reply_to=reply_to_msg_id
                                        )
                                    message_id = sent_msg.id if hasattr(sent_msg, 'id') else None
                                break
                            except FloodWaitError as e:
                                retry_count += 1
                                wait_time = e.seconds + random.randint(5, 10)
                                logger.warning(f"发送媒体遇到洪水控制，等待 {wait_time} 秒后重试 (第 {retry_count}/{max_retries} 次)")
                                await asyncio.sleep(wait_time)
                                if retry_count == max_retries:
                                    raise Exception(f"发送媒体失败，已达到最大重试次数: {str(e)}")
                            except Exception as e:
                                if retry_count == max_retries - 1:
                                    raise
                                retry_count += 1
                                wait_time = random.randint(10, 20)
                                logger.warning(f"发送媒体失败，等待 {wait_time} 秒后重试 (第 {retry_count}/{max_retries} 次): {str(e)}")
                                await asyncio.sleep(wait_time)
                        
                            
                    # 记录成功
                    result["data"]["success"].append({
                        "group_id": group_id,
                        "group_link": group_identifier if str(group_identifier).startswith(('http', 't.me')) else None,
                        "type": entity_type,
                        "message": "发送成功",
                        "message_id": message_id 
                    })
                    
                    # 添加随机延迟，避免触发Telegram反垃圾机制
                    random_delay = random.uniform(2, 5)  # 2-5秒的随机延迟
                    await asyncio.sleep(random_delay)
                    logger.debug(f"发送消息后等待 {random_delay:.2f} 秒")
                    
                except Exception as e:
                    # 记录失败（包含类型信息）
                    result["data"]["failed"].append({
                        "group_id": group_id,
                        "recognized_as_link": is_telegram_link,
                        "group_identifier": group_identifier,
                        "type": entity_type if 'entity_type' in locals() else "unknown",
                        "message": str(e)
                    })
                    
            
            # 整理结果
            result["status"] = True
            result["message"] = f"发送完成，成功{len(result['data']['success'])}个，失败{len(result['data']['failed'])}个{result['data']['failed']}"
            # 记录幂等性缓存 (覆盖处理中状态)
            if message_unique_id:
                try:
                    # 总是缓存最终结果，无论是否成功（这样重试时能知道上次失败了）
                    await self.cache.set(f"msg_idempotency:{message_unique_id}", result, ttl=600)
                except Exception as e:
                    logger.warning(f"[Idempotency] 设置缓存失败: {e}")
            
        except Exception as e:
            result["message"] = f"发送消息失败: {str(e)}"
            # 如果发生顶层异常，也需要更新缓存为失败状态，或者删除缓存允许重试
            # 这里选择更新缓存，避免死循环重试
            if message_unique_id:
                try:
                    await self.cache.set(f"msg_idempotency:{message_unique_id}", result, ttl=600)
                except Exception:
                    pass
            
        logger.info(
            f"[send_messages] 返回结果: status={result['status']}, message='{result['message']}', "
            f"success_cnt={len(result['data']['success'])}, failed_cnt={len(result['data']['failed'])}"
        )   
        return result 
    #检查用户是否为群组/频道成员#    
    async def _check_group_membership(self, entity, user_id, entity_type,client):
        """检查用户是否为群组/频道成员"""
        try:
            cache_key = f"{entity.id}:{user_id}:{entity_type}"
            now = time.time()
            cached = self._membership_cache.get(cache_key)
            if cached and (now - cached["timestamp"]) < MEMBERSHIP_CACHE_EXPIRE:
                return cached["value"]
            is_member = False
            if entity_type == "chat":  # 普通群组
                await self._wait_for_api_slot("get_participants", min_interval=0.5)
                participants = await client.get_participants(entity, limit=200)  # 限制获取数量避免性能问题
                is_member = any(participant.id == user_id for participant in participants)
                
            elif entity_type in ["megagroup", "channel"]:  # 超级群组/频道
                try:
                    await self._wait_for_api_slot("get_permissions", min_interval=0.5)
                    await client.get_permissions(entity, user_id)
                    is_member = True
                except Exception:
                    is_member = False
            self._membership_cache[cache_key] = {"timestamp": time.time(), "value": is_member}
            return is_member
        
        except Exception as e:
            logger.error(f"检查成员身份失败: {str(e)}")
            return False    
    # --------------------------
    # 一键删除所有好友
    # --------------------------
    @handle_account_status
    async def delete_all_contacts(self, client=None, current_user=None,result=None):
        """删除账号中所有好友（谨慎使用）"""
        result = {"status": False, "message": "", "data": {}}
        
        try:

            # 获取所有联系人
            contacts = await client(GetContactsRequest(hash=0))
            total = len(contacts.users)
            deleted = 0
            
            if total == 0:
                result["message"] = "没有好友可删除"
                result["status"] = True
                return result
            
            # 逐个删除好友（Telegram API限制批量删除，需循环处理）
            for user in contacts.users:
                # 构造用户ID对象
                peer = PeerUser(user_id=user.id)
                
                # 添加FloodWaitError处理和重试机制
                max_retries = 3
                retry_count = 0
                while retry_count < max_retries:
                    try:
                        # 调用删除接口
                        await self._wait_for_api_slot("delete_contact")
                        await client(DeleteContactsRequest(id=[peer]))
                        deleted += 1
                        break
                    except FloodWaitError as e:
                        retry_count += 1
                        wait_time = e.seconds + random.randint(5, 10)
                        logger.warning(f"删除联系人遇到洪水控制，等待 {wait_time} 秒后重试 (第 {retry_count}/{max_retries} 次)")
                        await asyncio.sleep(wait_time)
                        if retry_count == max_retries:
                            logger.error(f"删除联系人 {user.id} 失败，已达到最大重试次数")
                            break
                    except Exception as e:
                        logger.error(f"删除联系人 {user.id} 失败: {str(e)}")
                        break
                
                # 添加随机延迟，避免触发Telegram反垃圾机制
                random_delay = random.uniform(1, 3)  # 1-3秒的随机延迟
                await asyncio.sleep(random_delay)
                logger.debug(f"删除联系人后等待 {random_delay:.2f} 秒")
                
            result["status"] = True
            result["message"] = f"删除完成，共处理 {total} 个好友，成功删除 {deleted} 个"
            result["data"]["total"] = total
            result["data"]["deleted"] = deleted
            
        except Exception as e:
            result["message"] = f"删除好友失败: {str(e)}"
                
        return result

    # --------------------------
    # 一键退出所有群聊/频道
    # --------------------------
    @handle_account_status
    async def leave_all_groups(self, client=None, current_user=None, result=None):
        """
        安全退出所有群聊 / 超级群 / 频道（商用稳定版）
        """
        result = {
            "status": False,
            "message": "",
            "data": {}
        }
    
        # 普通群（Chat）
        normal_groups = []
        # 超级群（megagroup）
        super_groups = []
        # 频道（broadcast）
        channels = []
    
        try:
            # =========================
            # 1. 收集所有对话
            # =========================
            async for dialog in client.iter_dialogs(limit=100):
                if dialog.is_user:
                    continue
    
                entity = dialog.entity
    
                # 普通群 Chat
                if dialog.is_group and not dialog.is_channel:
                    normal_groups.append(entity)
    
                # Channel 类型
                elif dialog.is_channel:
                    # 超级群
                    if getattr(entity, "megagroup", False):
                        super_groups.append(entity)
                    # 广播频道
                    elif getattr(entity, "broadcast", False):
                        channels.append(entity)
    
            total = len(normal_groups) + len(super_groups) + len(channels)
            if total == 0:
                result["status"] = True
                result["message"] = "没有群聊或频道需要退出"
                return result
    
            logger.info(
                f"准备退出：普通群 {len(normal_groups)} 个，"
                f"超级群 {len(super_groups)} 个，"
                f"频道 {len(channels)} 个"
            )
    
            left = 0
            failed = []
    
            # =========================
            # 2. 退出普通群（风险最低）
            # =========================
            for entity in normal_groups:
                try:
                    await client(DeleteChatUserRequest(
                        chat_id=entity.id,
                        user_id="me"
                    ))
                    left += 1
                    await asyncio.sleep(random.uniform(8, 15))
    
                except FloodWaitError as e:
                    logger.warning(f"普通群 FloodWait {e.seconds}s，中断本账号任务")
                    await asyncio.sleep(e.seconds + 5)
                    break
    
    
                except Exception as e:
                    failed.append({
                        "id": entity.id,
                        "type": "group",
                        "error": str(e)
                    })
    
            # =========================
            # 3. 退出超级群
            # =========================
            for entity in super_groups:
                try:
                    await client(LeaveChannelRequest(entity))
                    left += 1
                    await asyncio.sleep(random.uniform(12, 20))
    
                except FloodWaitError as e:
                    logger.warning(f"超级群 FloodWait {e.seconds}s，中断")
                    await asyncio.sleep(e.seconds + 5)
                    break
    
                except Exception as e:
                    failed.append({
                        "id": entity.id,
                        "type": "supergroup",
                        "error": str(e)
                    })
    
            # =========================
            # 4. 退出频道（风险最高）
            # =========================
            for entity in channels:
                try:
                    await client(LeaveChannelRequest(entity))
                    left += 1
                    await asyncio.sleep(random.uniform(15, 25))
    
                except FloodWaitError as e:
                    logger.warning(f"频道 FloodWait {e.seconds}s，中断")
                    await asyncio.sleep(e.seconds + 5)
                    break
    
                except Exception as e:
                    failed.append({
                        "id": entity.id,
                        "type": "channel",
                        "error": str(e)
                    })
    
            # =========================
            # 5. 返回结果
            # =========================
            result["status"] = True
            result["message"] = (
                f"处理完成：共 {total} 个，成功 {left} 个，失败 {len(failed)} 个"
            )
            result["data"] = {
                "total": total,
                "left": left,
                "failed_count": len(failed),
                "failed": failed
            }
    
    
        except Exception as e:
            logger.exception("leave_all_groups 异常")
            result["message"] = f"退出失败: {str(e)}"
    
        return result


    # --------------------------
    # 退出所有其他登录设备
    # --------------------------
    @handle_account_status
    async def logout_other_sessions(self, client=None, current_user=None,result=None):
        """退出当前账号在其他设备的登录（保留当前会话）"""
        result = {"status": False, "message": "", "data": {}}
        
        try:
            
            # 获取所有登录授权（包括当前设备）
            authorizations = await client(GetAuthorizationsRequest())
            total = len(authorizations.authorizations)
            logged_out = 0
            
            if total <= 1:
                result["message"] = "没有其他设备登录"
                result["status"] = True
                return result
            
            # 遍历所有授权，只保留当前会话（排除当前设备）
            current_hash = None  # 当前会话的标识
            for auth in authorizations.authorizations:
                # 跳过当前设备的授权
                is_current = getattr(auth, 'current', False)
                if is_current:
                    current_hash = auth.hash
                    continue
                # 撤销其他设备的授权
                await client(ResetAuthorizationRequest(hash=auth.hash))
                logged_out += 1
            
           
            
            result["status"] = True
            result["message"] = f"操作完成，共检测到 {total} 个登录设备，成功退出 {logged_out} 个"
            result["data"]["total_devices"] = total
            result["data"]["logged_out"] = logged_out
            if logged_out > 0:
                try:
                    phone = getattr(current_user, 'phone', None)
                    await self.db.clear_mtuser_web_key_hex_by_phone(phone)
                except Exception:
                    pass
        except Exception as e:
            result["message"] = f"退出其他设备失败: {str(e)}"
                
        return result
        

    @handle_account_status
    async def get_account_info(self, client=None, current_user=None, result=None):
        """获取账号详细信息"""
        try:
            # 账号正常
            result["data"]["account_status"] = "正常"
            result["data"]["account_status_desc"] = "登录成功"
            result["status"] = True
            result["message"] = "登录成功"
     
            # 头像信息
            avatar_path = None
            try:
                if current_user.photo:  # 检查是否有头像
                    # 创建保存头像的目录（如果不存在）
                    avatar_dir = os.path.join(os.path.dirname(self.tdata_path), "avatars")
                    os.makedirs(avatar_dir, exist_ok=True)
            
                    # 基于用户ID固定文件名（避免重复下载）
                    user_id = current_user.id
                    avatar_filename = f"avatar_{user_id}.jpg"
                    avatar_path = os.path.join(avatar_dir, avatar_filename)
            
                    if not os.path.exists(avatar_path):  # 只有不存在时才下载
                        photo_bytes = await client.download_profile_photo(current_user, file=bytes)
                        if photo_bytes:
                            with open(avatar_path, "wb") as f:
                                f.write(photo_bytes)
                            logger.info(f"头像已保存到: {avatar_path}")
                    else:
                        logger.info(f"头像已存在，直接复用: {avatar_path}")
            
            except Exception as e:
                logger.warning(f"保存头像失败: {str(e)}")
                avatar_path = None  # 保存失败时置空

            
            # 获取好友数量
            contacts = await client(GetContactsRequest(hash=0))
            friends_count = len(contacts.users)
            
            # 获取群组数量
            dialogs = []
            async for dialog in client.iter_dialogs():
                if dialog.is_group or dialog.is_channel:
                    dialogs.append(dialog)
            groups_count = len(dialogs)
            
            # 填充返回数据
            result["data"]["username"] = current_user.username
            result["data"]["nickname"] = f"{current_user.first_name} {current_user.last_name or ''}".strip()
            result["data"]["status"] = parse_status(current_user.status)
            result["data"]["friends_count"] = friends_count
            result["data"]["groups_count"] = groups_count
            result["data"]["avatar_url"] = avatar_path  
            
        except Exception as e:
            result["message"] = f"获取账号信息失败: {str(e)}"
            result["data"]["account_status"] = "异常"
            result["data"]["account_status_desc"] = f"获取账号信息失败: {str(e)}"
            
        return result       
    
    # 新增修改功能方法
    """修改账号密码"""
    @handle_account_status
    async def change_password(self, current_password, new_password, client=None, current_user=None, result=None):
        try:
            # 执行密码修改
            await client(UpdatePasswordSettingsRequest(
                current_password=current_password,
                new_password=new_password,
                hint=""  # 可以添加密码提示
            ))
            
            result["status"] = True
            result["message"] = "密码修改成功"
            
        except Exception as e:
            result["message"] = f"密码修改失败: {str(e)}"
            result["data"]["account_status"] = "异常"
            result["data"]["account_status_desc"] = f"密码修改失败: {str(e)}"
            
        return result
    
    """更新头像（传入图片路径）"""
    @handle_account_status
    async def update_profile_photo(self, photo_path, client=None, current_user=None, result=None):
        try:
            # 检查图片文件是否存在
            if not os.path.isfile(photo_path):
                result["message"] = f"图片文件不存在: {photo_path}"
                return result
                
            # 上传新头像
            with open(photo_path, "rb") as f:
                await client(UploadProfilePhotoRequest(
                    file=await client.upload_file(f)
                ))
                
            result["status"] = True
            result["message"] = "头像更新成功"
           
            
        except Exception as e:
            result["message"] = f"头像更新失败: {str(e)}"
            result["data"]["account_status"] = "异常"
            result["data"]["account_status_desc"] = f"头像更新失败: {str(e)}"
            
        return result
    
    """修改昵称（first_name是名，last_name是姓）"""
    async def update_nickname(self, first_name, last_name=""):
        return await self._update_profile(
            first_name=first_name, 
            last_name=last_name
        )
        
    """修改个人签名（bio）"""
    async def update_bio(self, bio):
        return await self._update_profile(about=bio)
        
    """修改用户名（@后的唯一标识）"""
    @handle_account_status
    async def update_username(self, username, client=None, current_user=None, result=None):
        # 执行用户名修改
        try:
            # 如果新旧用户名相同，直接返回
            if current_user and current_user.username == username:
                result["status"] = False
                result["message"] = f"用户名与当前一致，无需修改: {username}"
                result["data"] = {"current_username": current_user.username}
                return result
            # 执行修改
            updated_user = await client(UpdateUsernameRequest(username))
            result["status"] = True
            result["message"] = "用户名修改成功"
        except FloodWaitError as e:
            result["status"] = False
            result["message"] = f"修改过于频繁，请等待 {e.seconds} 秒后再试"
            result["data"] = {"wait_seconds": e.seconds}
        except UsernameOccupiedError:
            result["status"] = False
            result["message"] = f"用户名 '{username}' 已被占用，请换一个"
        except UsernameInvalidError:
            result["status"] = False
            result["message"] = f"用户名 '{username}' 格式无效，请检查规则"
        except Exception as e:
            result["status"] = False
            result["message"] = f"修改失败: {str(e)}"
            
        return result
    """内部通用方法，用于更新个人资料"""    
    @handle_account_status
    async def _update_profile(self, *args, **kwargs):
        client = kwargs.get("client")
        result = kwargs.get("result", {"status": False, "message": ""})
        random_sleep = random.uniform(1, 4)  # 0-2秒随机延迟
        await asyncio.sleep(random_sleep)
        logger.info(f"[clientclient] 有 client: key={client}")
        if not client:
            result["status"] = False
            result["message"] = "未获取到client"
            return result
        # 只保留允许的字段
        allowed_fields = {"first_name", "last_name", "about"}
        profile_kwargs = {k: v for k, v in kwargs.items() if k in allowed_fields}
    
        if not profile_kwargs:
            result = kwargs.get("result", {"status": False, "message": ""})
            result["status"] = False
            result["message"] = "没有有效的资料字段"
            return result
    
        # 执行资料更新
        await client(UpdateProfileRequest(**profile_kwargs))
    
        # 获取更新后的信息
        user = await self._get_me_cached(client, force_refresh=True)
        result = kwargs.get("result", {"status": False, "message": ""})
        result["status"] = True
        result["message"] = "资料更新成功"
        result["data"] = {
            "updated_info": {
                "first_name": user.first_name,
                "last_name": user.last_name,
                "about": getattr(user, 'about', "")
            }
        }
        return result
        
    @handle_account_status    
    async def get_history(self, target_id, limit=50, offset=0, client=None, current_user=None,result=None):
        """
        获取指定会话的历史消息
        :param target_id: 目标会话ID（群组/好友/频道）
        :param limit: 消息数量限制
        :param offset: 偏移量（从第N条开始获取）
        :return: 历史消息列表
        """
        result = {"status": False, "message": "", "data": {"messages": [], "total": 0}}
        
        try:
            # 修复1：删除强制转换ID为负数的错误逻辑，保留原始ID
            target_id = int(target_id)
            
            # 修复2：获取会话实体（自动适配用户/群组/频道）
            try:
                entity = await self._get_entity_cached(client, target_id)
            except ValueError as e:
                # 处理实体不存在的情况
                result["message"] = f"会话不存在或无法访问: {str(e)}"
                return result
            
            # 修复3：优化消息总数计算（使用更高效的方法）
            try:
                # Telethon 支持直接获取消息计数（无需迭代所有消息）
                total_count = await client.get_messages_count(entity)
            except Exception:
                # 兼容不支持的库版本，降级为迭代计数（但限制最大迭代次数避免性能问题）
                total_count = 0
                async for _ in client.iter_messages(entity, limit=10000):  # 限制最大1万条
                    total_count += 1
            result["data"]["total"] = total_count
            
            # 获取历史消息（按时间倒序，offset控制分页）
            messages = []
            async for msg in client.iter_messages(
                entity,
                limit=limit,
                offset_id=offset,
                reverse=False  # 按时间正序排列（旧消息在前）
            ):
                sender_name = None
                if msg.sender:
                    if hasattr(msg.sender, 'first_name') or hasattr(msg.sender, 'last_name'):
                        first = getattr(msg.sender, 'first_name', '') or ''
                        #last = getattr(msg.sender, 'last_name', '') or '' {last}
                        sender_name = f"{first}".strip()
                    elif hasattr(msg.sender, 'title'):
                        # 群组 / 频道
                        sender_name = msg.sender.title
                # 处理回复消息相关信息
                is_reply = False  # 是否为回复消息
                reply_to_msg_id = None  # 被回复的消息ID
                reply_to_text = None  # 被回复的消息内容
                reply_to_sender_id = None
                if msg.reply_to:
                    is_reply = True
                    reply_to_msg_id = msg.reply_to.reply_to_msg_id
                    # 尝试获取被回复的消息内容（最多尝试获取1条）
                    if reply_to_msg_id:
                        try:
                            # 修复后代码
                            replied_msg = await client.get_messages(
                                entity,
                                ids=reply_to_msg_id,
                                limit=1
                            )
                            # 处理单个消息对象或消息列表的情况
                            if replied_msg:
                                # 判断是否为列表（部分版本返回列表，部分返回单个对象）
                                if isinstance(replied_msg, list):
                                    target_msg = replied_msg[0] if replied_msg else None
                                else:
                                    target_msg = replied_msg
                                    reply_to_sender_id = target_msg.sender_id
                                reply_to_text = target_msg.text or "（无文本内容）" if target_msg else "（未找到回复的消息）"
                                
                        except Exception as e:
                            reply_to_text = f"（获取回复内容失败: {str(e)}）"
                            reply_to_sender_id = None  
                            


                # 处理媒体消息（如图片、语音）
                media_type = ""
                media_url = ""
                if msg.media:
                    if hasattr(msg.media, "photo"):
                        media_type = "image"
                        # 下载图片为base64（注意：大图片可能导致性能问题）
                        photo_bytes = await client.download_media(msg.media, file=bytes)
                        
                        media_url = f"data:image/jpeg;base64,{base64.b64encode(photo_bytes).decode()}"
                        
                    elif hasattr(msg.media, "voice"):
                        media_type = "voice"
                        # 语音消息可保存为文件后返回URL，或同样转为base64
                        # voice_bytes = await self.client.download_media(msg.media, file=bytes)
                        # media_url = f"data:audio/ogg;base64,{base64.b64encode(voice_bytes).decode()}"
                
                messages.append({
                    "id": msg.id,
                    "text": msg.text or "",  # 避免None，统一为空字符串
                    "sender_name": sender_name or "Unknown",
                    "date": msg.date.isoformat(),  # 时间格式：ISO 8601
                    "sender_id": msg.sender_id,
                    "media_type": media_type,
                    "media_url": media_url,
                    "is_reply": is_reply,  # 新增：是否为回复消息
                    "reply_to_msg_id": reply_to_msg_id,  # 新增：被回复的消息ID
                    "reply_to_text": reply_to_text,  # 新增：被回复的消息内容
                    "reply_to_sender_id": reply_to_sender_id 
                })
            
            result["status"] = True
            result["message"] = f"成功获取{len(messages)}条历史消息"
            result["data"]["messages"] = messages
            
        except Exception as e:
            result["message"] = f"获取历史消息失败: {str(e)}"
           
                
        return result 
    
    @handle_account_status    
    async def count_total_unread(self, client=None, current_user=None,result=None):
        """统计所有对话中的未读消息总数（补充详细类型）"""
        result = {"status": False, "message": "", "data": {"total_unread": 0, "unread_chats": []}}
        
        try:
            
            total_unread = 0
            unread_chats = []
            
            # 遍历所有对话，补充精确类型
            async for dialog in client.iter_dialogs():
                if dialog.unread_count > 0:
                    # 精确判断会话类型（关键改进）
                    chat_type = "unknown"
                    if dialog.is_user:
                        chat_type = "private"  # 私聊
                    elif dialog.is_group:
                        chat_type = "group"    # 普通群组
                    elif dialog.is_channel:
                        # 区分频道和超级群组（超级群组本质是特殊频道）
                        entity = await self._get_entity_cached(client, dialog.id)
                        if hasattr(entity, 'megagroup') and entity.megagroup:
                            chat_type = "supergroup"  # 超级群组（归为群组类）
                        else:
                            chat_type = "channel"     # 普通频道
                    
                    total_unread += dialog.unread_count
                    unread_chats.append({
                        "chat_id": dialog.id,
                        "title": dialog.title,
                        "unread_count": dialog.unread_count,
                        "type": chat_type,  # 新增：精确类型
                        "is_group": dialog.is_group or chat_type == "supergroup",  # 超级群组也视为群组
                        "is_private": chat_type == "private"
                    })
            
            #await client.disconnect()
            
            result["status"] = True
            result["message"] = f"未读消息统计完成，共 {total_unread} 条未读消息"
            result["data"]["total_unread"] = total_unread
            result["data"]["unread_chats"] = unread_chats
            
        except Exception as e:
            result["message"] = f"统计未读消息失败: {str(e)}"
            #if self.client and self.client.is_connected():
            #    await self.client.disconnect()
                
        return result
    @handle_account_status
    async def mark_session_as_read(self, session_id, client=None, current_user=None,result=None):
        """
        标记会话中所有消息为已读（使用 send_read_acknowledge）
        """
        result = {"status": False, "message": "", "data": {}}
        try:
            # 获取 entity（不是 InputPeer）
            entity = await self._get_entity_cached(client, int(session_id))
            
            # ----------- 成员数获取 -----------
            participants_count = 1
    
            try:
                if isinstance(entity, types.Channel):
                    full = await client(functions.channels.GetFullChannelRequest(entity))
                    participants_count = full.full_chat.participants_count or 1
    
                elif isinstance(entity, types.Chat):
                    full = await client(functions.messages.GetFullChatRequest(entity.id))
                    participants_count = full.full_chat.participants_count or 1
    
            except Exception:
                participants_count = 1
                
            # 查找 dialog 获取最新消息 ID
            target_dialog = None
            async for dialog in client.iter_dialogs():
                if dialog.id == int(session_id):
                    target_dialog = dialog
                    break

            if not target_dialog:
                return {
                    "status": False,
                    "message": f"会话ID不存在或无法访问: {session_id}",
                    "data": {}
                }

            last_message_id = target_dialog.message.id if target_dialog.message else 0
            unread_count = target_dialog.unread_count or 0

            if last_message_id > 0:
                # 核心改动：直接用 send_read_acknowledge
                await client.send_read_acknowledge(entity, max_id=last_message_id)

                msg = f"会话 {session_id} 已标记为已读，共处理 {unread_count} 条未读消息"
            else:
                msg = f"会话 {session_id} 无消息可标记"

            result.update({
                "status": True,
                "message": msg,
                "data": {
                    "session_id": session_id,
                    "unread_count": unread_count,
                    "last_message_id": last_message_id,
                    'participants_count':participants_count
                    #"proxy_used": proxy_info is not None
                }
            })

        except (ValueError, PermissionError, RuntimeError) as e:
            result.update({"status": False, "message": str(e)})
        except Exception as e:
            result.update({"status": False, "message": f"标记失败: {str(e)}"})
    
 
    
        #finally:
            #if self.client and self.client.is_connected():
            #    await self.client.disconnect()
    
        return result
     
    @handle_account_status
    async def get_common_groupshh(self, target_id, client=None, current_user=None,result=None):
        """获取与目标用户的共同群组"""
        result = {"status": False, "message": "", "data": {"groups": []}}
        
        try:
            
            # 2. 获取当前账号的所有群组
            my_groups = []
            async for dialog in client.iter_dialogs():
                if dialog.is_group or (dialog.is_channel and hasattr(dialog.entity, 'megagroup') and dialog.entity.megagroup):
                    my_groups.append(dialog.entity.id)

            # 3. 检查目标用户是否在这些群组中
            common_groups = []
            target_id = int(target_id)
            
            for group_id in my_groups:
                try:
                    # 获取群组成员（搜索目标用户）
                    entity = await self._get_entity_cached(client, group_id)
                    participants = await client(GetParticipantsRequest(
                        channel=entity,
                        filter=ChannelParticipantsSearch(q=""),  # 搜索所有成员
                        offset=0,
                        limit=200,
                        hash=0
                    ))
                    
                    # 检查目标用户是否在成员列表中
                    is_member = any(
                        user.id == target_id 
                        for user in participants.users
                    )
                    
            
                    # 获取头像URL（如果存在）
                    avatar_url = None
                   
                    if entity.photo:
                        try:
                            # 下载头像为字节流
                            photo_bytes = await client.download_profile_photo(entity, file=bytes)
                            if photo_bytes:
                                import base64
                                avatar_url = f"data:image/jpeg;base64,{base64.b64encode(photo_bytes).decode()}"
                        except Exception:
                            avatar_url = None
                          
                    if is_member:
                        # 获取群组信息
                        common_groups.append({
                            "id": entity.id,
                            "title": entity.title,
                            "avatar_url": avatar_url,
                            "member_count": entity.participants_count if hasattr(entity, 'participants_count') else 0
                            
                        })
                        
                except (ChannelPrivateError, PeerIdInvalidError):
                    continue  # 跳过无权限的群组
                except Exception as e:
                    logger.info(f"检查群组 {group_id} 失败: {str(e)}")
                    continue

            # 4. 返回结果
            result["status"] = True
            result["data"]["groups"] = common_groups
            result["message"] = f"找到{len(common_groups)}个共同群组"
            #await self.client.disconnect()

        except Exception as e:
            result["message"] = f"获取共同群组失败: {str(e)}"
            #if self.client and self.client.is_connected():
            #    await self.client.disconnect()
        
        return result
