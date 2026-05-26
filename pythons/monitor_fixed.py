# monitor_fixed.py
# 修复Telethon错误和连接问题的改进版本

import asyncio
import logging
import os
import time
import random
from typing import Dict, Any, Optional, List, Callable
from datetime import datetime, timezone, timedelta
from telethon import TelegramClient, events
from telethon.tl.functions.contacts import GetContactsRequest
from telethon.tl.types import User, Channel, Chat, PeerUser, Dialog
from telethon.errors import (
    FloodWaitError, 
    RPCError,
    ValueError
)
from telethon.tl.functions.users import GetFullUserRequest
from telethon.tl.functions import PingRequest
from telethon.tl.functions.messages import GetDialogsRequest

from database import MySQLDatabase
from cache import RedisCache
from models import MessageModel, ChatModel, GroupMemberModel
from config import Config

logger = logging.getLogger(__name__)


class TelethonErrorHandler:
    """Telethon错误处理工具类"""
    
    @staticmethod
    async def safe_get_entity(client: TelegramClient, entity_id, entity_type="user", max_retries=3):
        """安全的实体获取方法，包含错误处理和重试机制"""
        for attempt in range(max_retries):
            try:
                # 根据类型创建对应的Peer对象
                if entity_type == "user":
                    peer = PeerUser(entity_id)
                elif entity_type == "chat":
                    peer = PeerChat(entity_id)
                elif entity_type == "channel":
                    peer = PeerChannel(entity_id)
                else:
                    raise ValueError(f"不支持的实体类型: {entity_type}")
                
                # 尝试获取实体
                entity = await client.get_entity(peer)
                return entity
                
            except ValueError as e:
                if "Could not find the input entity" in str(e):
                    logger.warning(f"实体未找到 (尝试 {attempt + 1}/{max_retries}): {entity_id}")
                    
                    if attempt < max_retries - 1:
                        # 尝试通过获取对话框来"遇到"实体
                        await TelethonErrorHandler._encounter_entity_via_dialogs(client)
                        await asyncio.sleep(1)  # 等待1秒后重试
                        continue
                    else:
                        # 最后一次尝试失败
                        raise ValueError(f"无法找到实体 {entity_id} ({entity_type})，请确保该实体在您的联系人列表或对话框中")
                else:
                    raise e
            except Exception as e:
                logger.error(f"获取实体时发生未知错误 (尝试 {attempt + 1}/{max_retries}): {e}")
                if attempt < max_retries - 1:
                    await asyncio.sleep(1)
                    continue
                else:
                    raise e
    
    @staticmethod
    async def _encounter_entity_via_dialogs(client: TelegramClient):
        """通过获取对话框列表来遇到实体"""
        try:
            dialogs = await client.get_dialogs(limit=50)
            logger.debug(f"获取了 {len(dialogs)} 个对话框，填充实体缓存")
        except Exception as e:
            logger.warning(f"获取对话框失败: {e}")
    
    @staticmethod
    async def check_session_permissions(session_path: str) -> bool:
        """检查会话文件权限"""
        try:
            if os.path.exists(session_path):
                # 检查文件是否可写
                if not os.access(session_path, os.W_OK):
                    logger.warning(f"会话文件不可写: {session_path}")
                    # 尝试修复权限
                    try:
                        os.chmod(session_path, 0o644)
                        logger.info(f"已修复会话文件权限: {session_path}")
                        return os.access(session_path, os.W_OK)
                    except Exception as e:
                        logger.error(f"无法修复会话文件权限: {e}")
                        return False
                return True
            else:
                logger.warning(f"会话文件不存在: {session_path}")
                return True  # 文件不存在，但可以创建
        except Exception as e:
            logger.error(f"检查会话权限失败: {e}")
            return False
    
    @staticmethod
    async def ensure_connection_health(client: TelegramClient, session_path: str) -> bool:
        """确保连接健康"""
        try:
            # 检查会话权限
            if not await TelethonErrorHandler.check_session_permissions(session_path):
                return False
            
            # 检查连接状态
            if not client.is_connected():
                logger.info("客户端未连接，尝试重新连接")
                await client.connect()
            
            # 测试Ping
            try:
                await client(PingRequest(ping_id=12345))
                return True
            except Exception as e:
                logger.error(f"Ping测试失败: {e}")
                return False
                
        except Exception as e:
            logger.error(f"确保连接健康失败: {e}")
            return False


class ImprovedTelegramMonitor:
    """改进的Telegram实时监听器"""
    
    def __init__(self, client: TelegramClient, account_id: str, db: MySQLDatabase, cache: RedisCache, session_path: str = None, io_lock: asyncio.Lock = None, conn_lock: asyncio.Lock = None):
        self.client = client
        self.account_id = account_id
        self.db = db
        self.cache = cache
        self.session_path = session_path
        self._io_lock = io_lock
        self._conn_lock = conn_lock or asyncio.Lock()
        self._db_sem = asyncio.Semaphore(4)
        
        # 状态标志
        self.is_monitoring = False
        self.monitor_task = None
        self.sync_task = None
        self.heartbeat_task = None
        self.watchdog_task = None
        self._heartbeat_count = 0
        
        # 缓存和锁
        self._cached_dialogs: Optional[List[Dialog]] = None
        self._cached_dialogs_time: Optional[float] = None
        self._cached_dialogs_ttl = 60
        
        self._dlg_lock = asyncio.Lock()
        self._contact_lock = asyncio.Lock()
        self._chat_sync_lock = asyncio.Lock()
        self._heartbeat_lock = asyncio.Lock()
        
        # 事件处理器
        self.handlers = {
            'new_message': [],
            'edit_message': [],
            'delete_message': [],
            'new_chat': [],
            'chat_update': [],
            'user_update': [],
            'account_update': [],
            'message_sync': [],
            'participants_update': []
        }
        
        # 错误处理
        self._error_count = 0
        self._max_error_count = 10
        self._last_error_time = None
        
        # 存储目录
        self._init_storage()
    
    def _init_storage(self):
        """初始化存储目录"""
        try:
            self.avatar_dir = os.path.join(Config.AVATAR_DIR, self.account_id)
            os.makedirs(os.path.join(self.avatar_dir, "users"), exist_ok=True)
            os.makedirs(os.path.join(self.avatar_dir, "groups"), exist_ok=True)
            os.makedirs(os.path.join(self.avatar_dir, "account"), exist_ok=True)
            
            self.media_dir = os.path.join(Config.MEDIA_DIR, self.account_id)
            os.makedirs(self.media_dir, exist_ok=True)
            
            logger.info(f"存储目录初始化完成: {self.account_id}")
        except Exception as e:
            logger.error(f"初始化存储目录失败: {e}")
    
    async def _safe_operation(self, operation, *args, **kwargs):
        """安全操作包装器，包含错误处理"""
        try:
            # 确保连接健康
            if not await TelethonErrorHandler.ensure_connection_health(self.client, self.session_path):
                logger.error("连接不健康，无法执行操作")
                return None
            
            return await operation(*args, **kwargs)
            
        except ValueError as e:
            if "Could not find the input entity" in str(e):
                logger.warning(f"实体未找到错误: {e}")
                # 这里可以添加特定的实体修复逻辑
                self._handle_entity_error(e)
            else:
                logger.error(f"值错误: {e}")
            return None
        except FloodWaitError as e:
            logger.warning(f"Flood等待错误，等待 {e.seconds} 秒: {e}")
            await asyncio.sleep(e.seconds)
            return await self._safe_operation(operation, *args, **kwargs)
        except RPCError as e:
            logger.error(f"RPC错误: {e}")
            self._handle_rpc_error(e)
            return None
        except Exception as e:
            logger.error(f"未知错误: {e}")
            self._handle_general_error(e)
            return None
    
    def _handle_entity_error(self, error):
        """处理实体错误"""
        self._error_count += 1
        self._last_error_time = time.time()
        
        if self._error_count >= self._max_error_count:
            logger.error("实体错误次数过多，可能需要重新登录")
            # 这里可以触发重新登录逻辑
    
    def _handle_rpc_error(self, error):
        """处理RPC错误"""
        self._error_count += 1
        
        if "AUTH_KEY_DUPLICATED" in str(error):
            logger.error("认证密钥重复，需要重新登录")
        elif "SESSION_REVOKED" in str(error):
            logger.error("会话已撤销，需要重新登录")
    
    def _handle_general_error(self, error):
        """处理一般错误"""
        self._error_count += 1
        
        if "readonly database" in str(error).lower():
            logger.error("数据库只读错误，检查文件权限")
            if self.session_path:
                TelethonErrorHandler.check_session_permissions(self.session_path)
    
    async def _get_safe_entity(self, entity_id, entity_type="user"):
        """安全的实体获取方法"""
        return await self._safe_operation(
            TelethonErrorHandler.safe_get_entity,
            self.client, entity_id, entity_type
        )
    
    async def _create_chat_record(self, message, chat_info: Dict):
        """改进的创建聊天记录方法"""
        try:
            # 使用安全的实体获取
            entity = await self._get_safe_entity(chat_info['oldchat_id'])
            if not entity:
                logger.error(f"无法获取实体: {chat_info['oldchat_id']}")
                return
            
            # 构建聊天信息
            if chat_info['chat_type'] == 'private' and isinstance(entity, User):
                title = self._get_user_display_name(entity)
                username = entity.username
                is_bot = entity.bot
                participants_count = 1
            else:
                title = getattr(entity, 'title', f"{chat_info['chat_type']}_{chat_info['oldchat_id']}")
                username = getattr(entity, 'username', None)
                is_bot = False
                
                # 获取参与者数量
                participants_count = await self._get_participants_count(entity)
            
            # 下载头像
            avatar_path = await self._download_user_avatar(entity, is_account=False)
            
            # 创建聊天模型
            chat_model = ChatModel(
                chat_id=chat_info['newchat_id'],
                account_id=self.account_id,
                chat_type=chat_info['chat_type'],
                title=title,
                username=username,
                unread_count=0,
                last_message_id=message.id,
                last_message_time=message.date,
                avatar_path=avatar_path,
                participants_count=participants_count,
                is_bot=is_bot
            )
            
            # 保存到数据库
            await self._db_run(self.db.save_chat, chat_model)
            
            logger.info(f"创建聊天室成功: {chat_info['newchat_id']} ({chat_info['chat_type']}), "
                       f"参与者数量: {participants_count}")
            
        except Exception as e:
            logger.error(f"创建聊天室失败: {e}")
    
    async def _get_participants_count(self, entity) -> int:
        """获取参与者数量"""
        try:
            if hasattr(entity, 'participants_count'):
                return entity.participants_count
            
            # 对于私聊，参与者数量为1
            if isinstance(entity, User):
                return 1
            
            # 对于群组和频道，尝试获取参与者
            try:
                participants = await self.client.get_participants(entity)
                return len(participants)
            except:
                return 1  # 默认值
                
        except Exception as e:
            logger.debug(f"获取参与者数量失败: {e}")
            return 1
    
    async def _get_sender_name(self, msg) -> str:
        """改进的获取发送者名称方法"""
        try:
            # 1. 尝试从 msg.sender 获取
            if msg.sender:
                if hasattr(msg.sender, 'first_name'):
                    first = msg.sender.first_name or ''
                    last = msg.sender.last_name or ''
                    name = f"{first} {last}".strip()
                    if name:
                        return name
                elif hasattr(msg.sender, 'username') and msg.sender.username:
                    return f"@{msg.sender.username}"
                elif hasattr(msg.sender, 'title'):
                    return msg.sender.title
            
            # 2. 尝试通过安全的实体获取
            user_id = await self._get_safe_sender_id(msg)
            if user_id:
                try:
                    user = await self._get_safe_entity(user_id)
                    if user:
                        if hasattr(user, 'first_name'):
                            first = user.first_name or ''
                            last = user.last_name or ''
                            name = f"{first} {last}".strip()
                            if name:
                                return name
                        elif hasattr(user, 'username') and user.username:
                            return f"@{user.username}"
                        elif hasattr(user, 'title'):
                            return user.title
                except Exception as e:
                    logger.debug(f"获取用户信息失败 {user_id}: {e}")
            
            # 3. 返回ID作为名称
            return f"User_{user_id}" if user_id else "Unknown"
            
        except Exception as e:
            logger.error(f"获取发送者名称失败: {e}")
            return "Unknown"
    
    async def _get_safe_sender_id(self, msg, chat_id=None):
        """安全的获取发送者ID方法"""
        try:
            user_id = None
            
            if hasattr(msg, 'from_id') and msg.from_id:
                if isinstance(msg.from_id, PeerUser):
                    user_id = msg.from_id.user_id
            elif hasattr(msg, 'sender_id') and msg.sender_id:
                if isinstance(msg.sender_id, PeerUser):
                    user_id = msg.sender_id.user_id
                elif isinstance(msg.sender_id, int):
                    user_id = msg.sender_id
            elif hasattr(msg, 'peer_id') and msg.peer_id:
                if isinstance(msg.peer_id, PeerUser):
                    user_id = msg.peer_id.user_id
            
            # 如果user_id仍然为空，尝试其他方法
            if user_id is None and chat_id:
                # 对于私聊，chat_id就是对方用户ID
                if str(chat_id).startswith('-'):
                    # 群组ID，无法确定发送者
                    pass
                else:
                    try:
                        user_id = int(chat_id)
                    except:
                        pass
            
            return user_id
            
        except Exception as e:
            logger.debug(f"获取安全发送者ID失败: {e}")
            return None
    
    async def _db_run(self, db_func, *args, **kwargs):
        """数据库操作包装器"""
        async with self._db_sem:
            return await db_func(*args, **kwargs)
    
    def _get_user_display_name(self, user: User) -> str:
        """获取用户显示名称"""
        if not user:
            return "未知用户"
        
        if hasattr(user, 'deleted') and user.deleted:
            return f"已删除用户{user.id}"
        
        if hasattr(user, 'bot') and user.bot:
            return f"机器人{user.id}"
        
        name_parts = []
        if hasattr(user, 'first_name') and user.first_name:
            name_parts.append(user.first_name)
        if hasattr(user, 'last_name') and user.last_name:
            name_parts.append(user.last_name)
        
        if name_parts:
            return " ".join(name_parts)
        elif hasattr(user, 'username') and user.username:
            return f"@{user.username}"
        elif hasattr(user, 'title') and user.title:
            return user.title
        else:
            return f"用户{user.id}"
    
    # 其他方法保持不变，但使用改进的错误处理
    async def start(self) -> Dict[str, Any]:
        """启动监听器"""
        try:
            # 确保连接健康
            if not await TelethonErrorHandler.ensure_connection_health(self.client, self.session_path):
                return {"status": False, "message": "连接不健康，无法启动监听"}
            
            # 原有的启动逻辑...
            self.is_monitoring = True
            
            # 启动各种任务
            self.monitor_task = asyncio.create_task(self._monitor_loop())
            self.sync_task = asyncio.create_task(self._sync_loop())
            self.heartbeat_task = asyncio.create_task(self._heartbeat_loop())
            self.watchdog_task = asyncio.create_task(self._watchdog_loop())
            
            logger.info(f"Telegram监听器启动成功: {self.account_id}")
            return {"status": True, "message": "监听器启动成功"}
            
        except Exception as e:
            logger.error(f"启动监听器失败: {e}")
            return {"status": False, "message": f"启动失败: {e}"}
    
    async def _monitor_loop(self):
        """监听循环"""
        while self.is_monitoring:
            try:
                # 使用安全的操作
                await self._safe_operation(self._do_monitor)
                await asyncio.sleep(1)
            except Exception as e:
                logger.error(f"监听循环错误: {e}")
                await asyncio.sleep(5)  # 错误后等待5秒
    
    async def _do_monitor(self):
        """实际的监听逻辑"""
        # 原有的监听逻辑...
        pass
    
    async def _heartbeat_loop(self):
        """心跳循环"""
        while self.is_monitoring:
            try:
                # 使用安全的Ping操作
                success = await self._safe_operation(
                    lambda: self.client(PingRequest(ping_id=12345))
                )
                
                if success:
                    self._heartbeat_count += 1
                    if self._heartbeat_count % 60 == 0:  # 每分钟记录一次
                        logger.debug(f"心跳正常: {self.account_id} (计数: {self._heartbeat_count})")
                
                await asyncio.sleep(1)
            except Exception as e:
                logger.error(f"心跳循环错误: {e}")
                await asyncio.sleep(5)
    
    async def _watchdog_loop(self):
        """看门狗循环，监控错误状态"""
        while self.is_monitoring:
            try:
                # 检查错误计数
                if self._error_count > self._max_error_count:
                    logger.error(f"错误计数过多 ({self._error_count})，可能需要重启")
                    # 这里可以添加重启逻辑
                
                # 重置错误计数（每小时）
                if self._last_error_time and time.time() - self._last_error_time > 3600:
                    self._error_count = 0
                    logger.info("错误计数已重置")
                
                await asyncio.sleep(60)  # 每分钟检查一次
            except Exception as e:
                logger.error(f"看门狗循环错误: {e}")
                await asyncio.sleep(60)


# 使用示例
async def test_improved_monitor():
    """测试改进的监听器"""
    # 创建客户端
    client = TelegramClient("test_session", api_id="your_api_id", api_hash="your_api_hash")
    
    # 创建数据库和缓存实例
    db = MySQLDatabase("test_account")
    cache = RedisCache()
    
    # 创建改进的监听器
    monitor = ImprovedTelegramMonitor(
        client=client,
        account_id="test_account",
        db=db,
        cache=cache,
        session_path="test_session.session"
    )
    
    # 启动监听器
    result = await monitor.start()
    print("启动结果:", result)
    
    # 运行一段时间
    await asyncio.sleep(10)
    
    # 停止监听器
    await monitor.stop()

if __name__ == "__main__":
    asyncio.run(test_improved_monitor())