# monitor.py (优化版本 - 包含聊天信息存储和未读数标注)
import asyncio
import logging
import os
import time
import json
from typing import Dict, Any, Optional, List, Callable, Tuple
from datetime import datetime, timedelta
from telethon import TelegramClient, events
from telethon.tl.functions.contacts import GetContactsRequest
from telethon.tl.types import User, Channel, Chat, Dialog, PeerUser, PeerChat, PeerChannel
from telethon.tl.functions.messages import GetDialogsRequest
from telethon.tl.types import InputPeerEmpty
from database import MySQLDatabase
from cache import RedisCache
from models import MessageModel, ChatModel,
from config import Config
from telethon.errors import FloodWaitError
from telethon.errors.rpcerrorlist import AuthKeyUnregisteredError


logger = logging.getLogger(__name__)

class TelegramMonitor:
    """Telegram实时监听器（优化版，包含聊天信息存储）"""
    
    def __init__(self, client: TelegramClient, account_id: str, db: MySQLDatabase, cache: RedisCache):
        self.client = client
        self.account_id = account_id
        self.db = db
        self.cache = cache
        self.is_monitoring = False
        self.monitor_task = None
        self.sync_task = None
        
        self._dlg_lock = asyncio.Lock()
        self._contact_lock = asyncio.Lock()
        self._chat_sync_lock = asyncio.Lock()

        # 事件处理器
        self.handlers = {
            'new_message': [],
            'edit_message': [],
            'delete_message': [],
            'new_chat': [],
            'chat_update': [],
            'user_update': [],
            'account_update': [],
            'message_sync': []
        }
        
        # 存储当前账号的所有聊天信息
        self._chats_cache: Dict[int, Dict] = {}
    
    def add_handler(self, event_type: str, callback: Callable):
        """添加事件处理器"""
        if event_type in self.handlers:
            self.handlers[event_type].append(callback)
            logger.debug(f"添加事件处理器: {event_type}")
    
    # 启动监听器
    async def start(self, sync_interval: int = None):
        if self.is_monitoring:
            return {"status": False, "message": "已在监听中"}
        
        try:
            self.is_monitoring = True
            
            # 启动定期同步任务
            interval = sync_interval or Config.MONITOR_SYNC_INTERVAL
            self.sync_task = asyncio.create_task(self._sync_loop(interval))
            
            # 初始全量同步（包含聊天信息）
            await self._full_sync()
            
            logger.info(f"监听器启动成功: {self.account_id}")
            return {
                "status": True,
                "message": "监听器启动成功",
                "data": {
                    "account_id": self.account_id,
                    "sync_interval": interval,
                    "start_time": datetime.now().isoformat()
                }
            }
            
        except Exception as e:
            logger.error(f"启动监听器失败: {str(e)}")
            return {
                "status": False,
                "message": f"启动监听器失败: {str(e)}"
            }
    
    # 停止监听器
    async def stop(self):
        """停止监听器"""
        try:
            if self.sync_task:
                self.sync_task.cancel()
                try:
                    await self.sync_task
                except asyncio.CancelledError:
                    pass
            
            self.is_monitoring = False
            
            # 触发事件
            for handler in self.handlers['account_update']:
                await handler({
                    'type': 'monitor_stopped',
                    'account_id': self.account_id,
                    'timestamp': datetime.now()
                })
            
            logger.info(f"监听器已停止: {self.account_id}")
            
            return {
                "status": True,
                "message": "监听器已停止",
                "data": {"account_id": self.account_id}
            }
            
        except Exception as e:
            logger.error(f"停止监听器失败: {str(e)}")
            return {
                "status": False,
                "message": f"停止监听器失败: {str(e)}"
            }
    
    # 同步循环
    async def _sync_loop(self, interval: int):
        """同步循环"""
        while self.is_monitoring:
            try:
                # 增量同步
                await self._incremental_sync()                
                # 等待下次同步
                await asyncio.sleep(interval)
                
            except asyncio.CancelledError:
                break
            except Exception as e:
                logger.error(f"同步循环错误: {str(e)}")
                await asyncio.sleep(min(interval, 30))
    
    # 全量同步
    async def _full_sync(self):
        """全量同步"""
        try:
            logger.info(f"开始全量同步: {self.account_id}")
            
            # 同步账号信息
            #await self._sync_account_info()
            
            # 同步所有聊天（群组和私聊）信息
            await self._sync_all_chats()
            
            # 同步未读消息统计
            await self._emit_private_unread()
            
            logger.info(f"全量同步完成: {self.account_id}")
            
        except Exception as e:
            logger.error(f"全量同步失败: {str(e)}")
    
    # 增量同步
    async def _incremental_sync(self):
        """增量同步"""
        try:
            # 检查连接状态
            if not self.client.is_connected():
                logger.warning(f"[{self.account_id}] 同步前检查到连接断开")
                try:
                    await self.client.connect()
                    if not await self.client.is_user_authorized():
                        logger.error(f"[{self.account_id}] 同步时未授权")
                        await self._handle_auth_failure()
                        return
                except Exception as e:
                    logger.error(f"[{self.account_id}] 同步时重连失败: {str(e)}")
                    await self._handle_auth_failure()
                    return
            
            # 增量同步聊天信息
            await self._sync_chats_incremental()
            
            # 同步未读消息
            await self._emit_private_unread()
            
        except AuthKeyUnregisteredError as e:
            logger.error(f"[{self.account_id}] 账号认证失效，停止监听: {str(e)}")
            await self._handle_auth_failure()
            return
            
        except Exception as e:
            logger.error(f"增量同步失败: {str(e)}")
    
    async def _handle_auth_failure(self):
        """处理认证失效的情况"""
        try:
            # 停止监听
            await self.stop()
            
            # 更新账号状态为离线或需要重新登录
            await self._update_account_auth_failed()
            
            logger.warning(f"[{self.account_id}] 账号认证失效，监听已停止")
            
        except Exception as e:
            logger.error(f"处理认证失效失败: {str(e)}")
    
    async def _update_account_auth_failed(self):
        """更新账号为认证失败状态"""
        try:
            # 清理缓存中的账号信息
            cache_keys = [
                f"account_info:{self.account_id}",
                f"dlg_hash:{self.account_id}",
                f"group_count:{self.account_id}",
                f"friend_count:{self.account_id}",
                f"chats_hash:{self.account_id}",
                f"chats_cache:{self.account_id}"
            ]
            
            for key in cache_keys:
                await self.cache.delete(key)
                
            logger.info(f"[{self.account_id}] 账号状态已更新为认证失效")
            
        except Exception as e:
            logger.error(f"更新账号认证失败状态异常: {str(e)}")
    
    # 同步账号信息
    async def _sync_account_info(self):
        """同步账号信息"""
        try:
            me = await self.client.get_me()
            
            # 获取统计信息
            contacts = await self._safe_get_contacts()
            non_bot_friends = 0
            if contacts and hasattr(contacts, 'users'):
                non_bot_friends = sum(1 for user in contacts.users if not user.bot)
            
            # 获取群组数量
            dialogs = await self._safe_iter_dialogs(limit=100)
            groups_count = 0
            for d in dialogs:
                if hasattr(d, 'is_group') and d.is_group:
                    groups_count += 1
                elif hasattr(d, 'is_channel') and d.is_channel:
                    groups_count += 1
            
            # 创建账号模型
            account_model = AccountModel(
                account_id=self.account_id,
                username=me.username,
                phone=me.phone,
                first_name=me.first_name,
                last_name=me.last_name,
                avatar_path=None,  # 如果需要头像，可以单独处理
                status="online",
                friends_count=non_bot_friends,
                groups_count=groups_count,
                last_login=datetime.now(),
                last_sync=datetime.now()
            )
            
            # 保存到数据库
            await self.db.save_account_info(account_model)
            
            logger.info(f"账号信息同步完成: {self.account_id}")
            
        except Exception as e:
            logger.error(f"同步账号信息失败: {str(e)}")
    
    # ============================================
    # 聊天信息同步相关方法
    # ============================================
    
    async def _sync_all_chats(self):
        """同步所有聊天信息（群组和私聊）"""
        try:
            dialogs = await self._safe_iter_dialogs(limit=200)
            
            if not dialogs:
                logger.warning(f"[{self.account_id}] 获取dialogs为空")
                return
            
            # 批量处理聊天信息
            chats_to_save = []
            for dialog in dialogs:
                chat_info = await self._extract_chat_info(dialog)
                if chat_info:
                    chats_to_save.append(chat_info)
            
            # 批量保存到数据库
            if chats_to_save:
                await self.db.batch_save_chats(chats_to_save)
                logger.info(f"[{self.account_id}] 保存了 {len(chats_to_save)} 个聊天信息")
            
            # 更新缓存
            await self._update_chats_cache(chats_to_save)
            
        except Exception as e:
            logger.error(f"[{self.account_id}] 同步所有聊天信息失败: {str(e)}")
    
    async def _sync_chats_incremental(self):
        """增量同步聊天信息"""
        try:
            dialogs = await self._safe_iter_dialogs(limit=200)
            
            if not dialogs:
                return
            
            # 从缓存获取之前的聊天信息
            cached_chats = await self._get_cached_chats()
            
            # 检查是否有变化
            changed_chats = []
            for dialog in dialogs:
                chat_info = await self._extract_chat_info(dialog)
                if not chat_info:
                    continue
                
                chat_id = chat_info.get('chat_id')
                cached_info = cached_chats.get(chat_id) if cached_chats else None
                
                # 检查是否有变化（标题、未读数等）
                if not cached_info or self._has_chat_changed(cached_info, chat_info):
                    changed_chats.append(chat_info)
            
            # 如果有变化的聊天，更新数据库
            if changed_chats:
                await self.db.batch_save_chats(changed_chats)
                logger.info(f"[{self.account_id}] 更新了 {len(changed_chats)} 个聊天信息")
                
                # 更新缓存
                for chat_info in changed_chats:
                    chat_id = chat_info.get('chat_id')
                    if cached_chats:
                        cached_chats[chat_id] = chat_info
                
                if cached_chats:
                    await self._set_cached_chats(cached_chats)
            
        except Exception as e:
            logger.error(f"[{self.account_id}] 增量同步聊天信息失败: {str(e)}")
    
    async def _extract_chat_info(self, dialog) -> Optional[Dict]:
        """从dialog中提取聊天信息"""
        try:
            chat_info = {
                'account_id': self.account_id,
                'unread_count': getattr(dialog, 'unread_count', 0) or 0,
                'last_message_time': datetime.now(),
                'synced_at': datetime.now()
            }
            
            # 获取实体（聊天对象）
            entity = dialog.entity
            
            if isinstance(entity, User):
                # 私聊用户
                chat_info.update({
                    'chat_id': f"{entity.id}",
                    'chat_type': 'private',
                    'title': self._get_user_display_name(entity),
                    'username': entity.username,
                    'is_bot': entity.bot,
                    'participants_count': 1,
                    'chat_data': {
                        'user_id': entity.id,
                        'first_name': entity.first_name,
                        'last_name': entity.last_name,
                        'phone': entity.phone
                    }
                })
                
            elif isinstance(entity, Channel) or isinstance(entity, Chat):
                # 群组或频道
                chat_type = 'channel' if getattr(entity, 'broadcast', False) else 'group'
                title = getattr(entity, 'title', '未知群组')
                username = getattr(entity, 'username', None)
                
                chat_info.update({
                    'chat_id': f"-{entity.id}",
                    'chat_type': chat_type,
                    'title': title,
                    'username': username,
                    'is_bot': False,
                    'participants_count': getattr(entity, 'participants_count', 0),
                    'chat_data': {
                        'entity_id': entity.id,
                        'title': title,
                        'username': username,
                        'creator': getattr(entity, 'creator', False),
                        'admin': getattr(entity, 'admin_rights', None) is not None
                    }
                })
            
            else:
                # 未知类型，跳过
                return None
            
            return chat_info
            
        except Exception as e:
            logger.error(f"提取聊天信息失败: {str(e)}")
            return None
    
    def _get_user_display_name(self, user: User) -> str:
        """获取用户显示名称"""
        if user.first_name and user.last_name:
            return f"{user.first_name} {user.last_name}"
        elif user.first_name:
            return user.first_name
        elif user.last_name:
            return user.last_name
        elif user.username:
            return user.username
        else:
            return f"用户{user.id}"
    
    def _has_chat_changed(self, old_info: Dict, new_info: Dict) -> bool:
        """检查聊天信息是否有变化"""
        # 检查关键字段是否变化
        fields_to_check = ['title', 'unread_count', 'username']
        
        for field in fields_to_check:
            if old_info.get(field) != new_info.get(field):
                return True
        
        # 检查聊天数据是否有重大变化
        old_participants = old_info.get('participants_count', 0)
        new_participants = new_info.get('participants_count', 0)
        
        if abs(new_participants - old_participants) > 10:  # 参与者数量变化超过10
            return True
        
        return False
    
    # ============================================
    # 缓存相关方法
    # ============================================
    
    async def _get_cached_chats(self) -> Dict[str, Dict]:
        """从缓存获取聊天信息"""
        try:
            cache_key = f"chats_cache:{self.account_id}"
            cached_data = await self.cache.get(cache_key)
            if cached_data:
                return cached_data
        except Exception as e:
            logger.error(f"获取缓存聊天信息失败: {str(e)}")
        return {}
    
    async def _set_cached_chats(self, chats: Dict[str, Dict]):
        """设置聊天信息缓存"""
        try:
            cache_key = f"chats_cache:{self.account_id}"
            await self.cache.set(cache_key, chats, Config.CACHE_TTL['chats'])
        except Exception as e:
            logger.error(f"设置缓存聊天信息失败: {str(e)}")
    
    async def _update_chats_cache(self, chats_list: List[Dict]):
        """更新聊天缓存"""
        try:
            chats_dict = {}
            for chat in chats_list:
                chat_id = chat.get('chat_id')
                if chat_id:
                    chats_dict[chat_id] = chat
            
            await self._set_cached_chats(chats_dict)
            self._chats_cache = chats_dict
            
        except Exception as e:
            logger.error(f"更新聊天缓存失败: {str(e)}")
    
    # ============================================
    # 未读数统计方法（优化版）
    # ============================================
    
    async def _emit_private_unread(self):
        """统计未读、群组数量、好友数量，并更新聊天未读数"""
        try:
            # 获取dialogs
            dialogs = await self._safe_iter_dialogs(limit=100)
            
            if not dialogs:
                logger.warning(f"[{self.account_id}] 获取dialogs为空")
                return
            
            # 统计总体未读数
            total_unread = 0
            # 更新每个聊天的未读数
            chats_to_update = []
            
            for dialog in dialogs:
                unread_count = getattr(dialog, 'unread_count', 0) or 0
                
                # 累加总未读数（只统计私聊）
                if hasattr(dialog, 'is_user') and dialog.is_user:
                    total_unread += unread_count
                
                # 提取聊天信息并更新未读数
                chat_info = await self._extract_chat_info(dialog)
                if chat_info:
                    chats_to_update.append(chat_info)
            
            # 批量更新聊天未读数到数据库
            if chats_to_update:
                await self.db.batch_update_chat_unread(chats_to_update)
            
            # 统计群组数量
            dialog_ids = sorted([d.id for d in dialogs])
            dialog_hash = hash(tuple(dialog_ids))
            
            key_hash = f"dlg_hash:{self.account_id}"
            key_groups = f"group_count:{self.account_id}"
            
            prev_hash = await self.cache.get(key_hash)
            
            if prev_hash and str(prev_hash) == str(dialog_hash):
                cached_groups = await self.cache.get(key_groups)
                group_count = int(cached_groups) if cached_groups else 0
            else:
                group_count = 0
                for d in dialogs:
                    if hasattr(d, 'is_group') and d.is_group:
                        group_count += 1
                    elif hasattr(d, 'is_channel') and d.is_channel:
                        group_count += 1
                
                await self.cache.set(key_hash, dialog_hash, 3600)
                await self.cache.set(key_groups, group_count, 3600)
            
            # 统计好友数量
            key_friends = f"friend_count:{self.account_id}"
            cached_friends = await self.cache.get(key_friends)
            
            if cached_friends is not None:
                friend_count = int(cached_friends)
            else:
                contacts = await self._safe_get_contacts()
                if contacts and hasattr(contacts, 'users'):
                    friend_count = 0
                    for user in contacts.users:
                        if not user.bot:
                            friend_count += 1
                    await self.cache.set(key_friends, friend_count, 3600)
                else:
                    friend_count = 0
                    await self.cache.set(key_friends, 0, 300)
            
            # 更新账号统计到数据库
            try:
                me = await self.client.get_me()
                phone = getattr(me, "phone", None)
                
                if phone:
                    key_db = f"db_state:{phone}"
                    prev_state = await self.cache.get(key_db)
                    
                    current_state = {
                        "unread": total_unread,
                        "groups": group_count,
                        "friends": friend_count
                    }
                    
                    # 如果状态发生变化，更新数据库
                    if prev_state != current_state:
                        await self.db.update_mtuser_unread_by_phone(
                            phone,
                            total_unread,
                            group_count,
                            friend_count
                        )
                        await self.cache.set(key_db, current_state, 600)
                        
                        logger.info(f"[{self.account_id}] 更新统计: 未读={total_unread}, "f"群组={group_count}, 好友={friend_count}")
                        
            except Exception as e:
                logger.error(f"[{self.account_id}] 写数据库失败: {str(e)}")
                
        except Exception as e:
            logger.error(f"[{self.account_id}] 统计私聊未读失败: {str(e)}")
    
    # ============================================
    # 安全封装方法
    # ============================================
    
    async def _safe_iter_dialogs(self, limit=200):
        """安全获取dialogs（带重试机制）"""
        async with self._dlg_lock:
            for attempt in range(3):
                try:
                    dialogs = [d async for d in self.client.iter_dialogs(limit=limit)]
                    return dialogs
    
                except FloodWaitError as e:
                    logger.warning(f"[{self.account_id}] iter_dialogs FloodWait {e.seconds}s")
                    await asyncio.sleep(e.seconds)
    
                except Exception as e:
                    logger.warning(f"[{self.account_id}] iter_dialogs失败({attempt+1}/3): {e}")
                    await asyncio.sleep(1.5 * (attempt + 1))
        
        logger.error(f"[{self.account_id}] iter_dialogs 失败超过 3 次")
        return []
    
    async def _safe_get_contacts(self):
        """安全获取联系人（带重试机制）"""
        async with self._contact_lock:
            for attempt in range(3):
                try:
                    return await self.client(GetContactsRequest(hash=0))
    
                except FloodWaitError as e:
                    logger.warning(f"[{self.account_id}] GetContacts FloodWait {e.seconds}s")
                    await asyncio.sleep(e.seconds)
    
                except Exception as e:
                    logger.warning(f"[{self.account_id}] GetContacts失败({attempt+1}/3): {e}")
                    await asyncio.sleep(1.2 * (attempt + 1))
    
        logger.warning(f"[{self.account_id}] GetContacts 失败超过 3 次")
        return None
    
    # ============================================
    # 工具方法
    # ============================================
    
    async def get_chat_info(self, chat_id: str) -> Optional[Dict]:
        """获取指定聊天信息"""
        if not self._chats_cache:
            self._chats_cache = await self._get_cached_chats()
        
        return self._chats_cache.get(chat_id)
    
    async def get_all_chats(self) -> List[Dict]:
        """获取所有聊天信息"""
        if not self._chats_cache:
            self._chats_cache = await self._get_cached_chats()
        
        return list(self._chats_cache.values())
    
    async def get_unread_summary(self) -> Dict:
        """获取未读消息摘要"""
        try:
            me = await self.client.get_me()
            phone = getattr(me, "phone", None)
            
            if phone:
                key_db = f"db_state:{phone}"
                state = await self.cache.get(key_db)
                if state:
                    return state
            
            return {
                "unread": 0,
                "groups": 0,
                "friends": 0
            }
        except Exception as e:
            logger.error(f"获取未读摘要失败: {str(e)}")
            return {
                "unread": 0,
                "groups": 0,
                "friends": 0
            }