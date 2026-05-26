# monitor.py (优化版本 - 优先使用缓存)
import asyncio
import logging
import os
import time
from typing import Dict, Any, Optional, List, Callable
from datetime import datetime, timedelta
from telethon import TelegramClient, events
from telethon.tl.functions.contacts import GetContactsRequest
from telethon.tl.types import User, Channel, Chat,PeerUser
from database import MySQLDatabase
from cache import RedisCache
from models import   MessageModel, ChatModel
from config import Config
from telethon.errors import  FloodWaitError

logger = logging.getLogger(__name__)

class TelegramMonitor:
    """Telegram实时监听器（不处理机器人信息）"""
    
    def __init__(self, client: TelegramClient, account_id: str,db: MySQLDatabase, cache: RedisCache):
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
        
        # 文件存储目录
        self._init_storage()
    
    def _init_storage(self):
        """初始化存储目录"""
        try:
            # 头像目录
            self.avatar_dir = os.path.join(Config.AVATAR_DIR, self.account_id)
            os.makedirs(os.path.join(self.avatar_dir, "users"), exist_ok=True)
            os.makedirs(os.path.join(self.avatar_dir, "groups"), exist_ok=True)
            os.makedirs(os.path.join(self.avatar_dir, "account"), exist_ok=True)
            
            # 媒体目录
            self.media_dir = os.path.join(Config.MEDIA_DIR, self.account_id)
            os.makedirs(self.media_dir, exist_ok=True)
            
            logger.info(f"存储目录初始化完成: {self.account_id}")
        except Exception as e:
            logger.error(f"初始化存储目录失败: {str(e)}")
    
    def add_handler(self, event_type: str, callback: Callable):
        """添加事件处理器"""
        if event_type in self.handlers:
            self.handlers[event_type].append(callback)
            logger.debug(f"添加事件处理器: {event_type}")
    
    async def start(self, sync_interval: int = None):
        """启动监听器"""
        if self.is_monitoring:
            return {"status": False, "message": "已在监听中"}
        
        try:
            # 设置事件处理器
            
            self.client.add_event_handler(
                self._handle_new_message,
                events.NewMessage
            )
            self.client.add_event_handler(
                self._handle_chat_update,
                events.ChatAction
            )
            
            self.is_monitoring = True
            
            # 启动监控循环
            self.monitor_task = asyncio.create_task(self._monitor_loop())
            
            # 启动定期同步任务
            interval = sync_interval or Config.MONITOR_SYNC_INTERVAL
            #self.sync_task = asyncio.create_task(self._sync_loop(interval))
            
            #await self._full_sync()
            
            # 触发事件
            for handler in self.handlers['account_update']:
                await handler({
                    'type': 'monitor_started',
                    'account_id': self.account_id,
                    'timestamp': datetime.now()
                })
            
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
    
    async def stop(self):
        """停止监听器"""
        try:
            # 取消任务
            if self.monitor_task:
                self.monitor_task.cancel()
                try:
                    await self.monitor_task
                except asyncio.CancelledError:
                    pass
            
            if self.sync_task:
                self.sync_task.cancel()
                try:
                    await self.sync_task
                except asyncio.CancelledError:
                    pass
            
            # 移除事件处理器
            
            try:
                self.client.remove_event_handler(self._handle_new_message)
                self.client.remove_event_handler(self._handle_chat_update)
            except:
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
    
    async def _monitor_loop(self):
        """监控循环"""
        while self.is_monitoring:
            try:
                # 检查连接状态
                if not self.client.is_connected():
                    logger.warning(f"客户端连接断开，尝试重连: {self.account_id}")
                    await self.client.connect()
                
                await asyncio.sleep(40)  # 10秒检查一次
                
            except asyncio.CancelledError:
                break
            except Exception as e:
                logger.error(f"监控循环错误: {str(e)}")
                await asyncio.sleep(40)
    
    async def _sync_loop(self, interval: int):
        """同步循环"""
        while self.is_monitoring:
            try:
                # 增量同步（跳过机器人）
                await self._incremental_sync()                
                # 更新缓存
                #await self._update_caches()                
                # 等待下次同步
                await asyncio.sleep(interval)
                
            except asyncio.CancelledError:
                break
            except Exception as e:
                logger.error(f"同步循环错误: {str(e)}")
                await asyncio.sleep(interval)
    # 初始化同步
    async def _full_sync(self):
        """全量/最小同步"""
        try:
            logger.info(f"开始全量同步: {self.account_id}")
      
            # 同步聊天数据
            await self._sync_chats()
            # 同步未读消息  
            await self._sync_unread_messages()
            # 同步未读消息统计
            await self._emit_private_unread()
            logger.info(f"全量同步完成: {self.account_id}")
            
        except Exception as e:
            logger.error(f"全量同步失败: {str(e)}")
    #循环同步
    async def _incremental_sync(self):
        """增量/最小同步"""
        try:
            # 检查新消息
            await self._check_new_messages()     
            # 同步未读消息统计
            await self._emit_private_unread()
            
        except Exception as e:
            logger.error(f"增量同步失败: {str(e)}")
    


    # 同步聊天室会话
    async def _sync_chats(self):
        """同步聊天会话"""
        try:
            chats_count = 0            
            async for dialog in self.client.iter_dialogs(limit=100):
                await self._process_chat(dialog)
                chats_count += 1
            
            logger.info(f"聊天会话同步完成: {chats_count} 个会话")
            
        except Exception as e:
            logger.error(f"同步聊天会话失败: {str(e)}")
    # 从缓存获取聊天室数据
    async def _get_cached_chat(self, chat_id: int):
        """从缓存获取聊天数据"""
        try:
            # 聊天数据通常较小，直接查数据库即可
            return await self.db.get_chat(chat_id, self.account_id)
        except Exception as e:
            logger.error(f"获取聊天数据失败 {chat_id}: {str(e)}")
            return None
    # 处理聊天室会话
    async def _process_chat(self, dialog):
        """处理聊天会话"""
        try:
            # 检查聊天是否已存在
            existing_chat = await self._get_cached_chat(dialog.id)
            
            # 确定聊天类型
            chat_type = self._get_chat_type(dialog)
            
            # 获取头像路径
            avatar_path = None
            if dialog.is_user:
                avatar_path = await self._download_user_avatar(dialog.entity)
            else:
                avatar_path = await self._download_group_avatar(dialog.entity)
            
            # 创建聊天模型
            chat_model = ChatModel(
                chat_id=dialog.id,
                account_id=self.account_id,
                chat_type=chat_type,
                title=dialog.title,
                unread_count=dialog.unread_count,
                last_message_id=dialog.message.id if dialog.message else None,
                last_message_time=dialog.message.date if dialog.message else datetime.now(),
                avatar_path=avatar_path,
                participants_count=dialog.entity.participants_count if hasattr(dialog.entity, 'participants_count') else 0
            )
            
            # 如果聊天已存在且数据相同，跳过保存
            if existing_chat and self._is_chat_unchanged(existing_chat, chat_model):
                logger.debug(f"聊天数据未变化，跳过保存: {dialog.id}")
                
                # 只更新未读计数和最后消息时间
                #existing_chat.unread_count = dialog.unread_count
                #existing_chat.last_message_time = datetime.now()
                #await self.db.update_chat(existing_chat)
                
                return
            
            # 保存到数据库
            await self.db.save_chat(chat_model)
            
        except Exception as e:
            logger.error(f"处理聊天失败 {dialog.id}: {str(e)}")
    # 检查聊天室数据是否发生变化
    def _is_chat_unchanged(self, old_chat: ChatModel, new_chat: ChatModel) -> bool:
        """检查聊天数据是否发生变化"""
        return (
            old_chat.title == new_chat.title and
            old_chat.chat_type == new_chat.chat_type and
            old_chat.participants_count == new_chat.participants_count
        )
    # 同步未读聊天室消息（跳过机器人消息）
    async def _sync_unread_messages(self):
        """同步未读消息（跳过机器人消息）"""
        try:
            unread_total = 0
            processed_count = 0
            skipped_bot_count = 0
            
            async for dialog in self.client.iter_dialogs(limit=100):
                if dialog.unread_count > 0:
                    result = await self._process_unread_messages(dialog)
                    unread_total += dialog.unread_count
                    processed_count += result.get('processed', 0)
                    skipped_bot_count += result.get('skipped_bot', 0)
            
            logger.info(f"未读消息同步完成: 共 {unread_total} 条未读消息，处理 {processed_count} 条，跳过 {skipped_bot_count} 条机器人消息")
            
        except Exception as e:
            logger.error(f"同步未读消息失败: {str(e)}")
    # 从缓存获取聊天消息数据
    async def _get_cached_message(self, message_id: int, chat_id: int):
        """从缓存获取消息数据，缓存未命中则查数据库"""
        try:
            # 1. 先检查缓存列表
            messages_list = await self.cache.get_list("messages", chat_id, 0, -1)
            if messages_list:
                for msg_dict in messages_list:
                    if msg_dict.get('message_id') == message_id:
                        logger.debug(f"消息缓存命中: chat={chat_id}, msg={message_id}")
                        return MessageModel.from_dict(msg_dict)
            
            # 2. 缓存未命中，查询数据库
            logger.debug(f"消息缓存未命中，查询数据库: chat={chat_id}, msg={message_id}")
            message = await self.db.get_message(message_id, chat_id, self.account_id)
            
            # 3. 如果数据库中存在，添加到缓存列表
            if message:
                await self.cache.add_to_list(
                    "messages",
                    message.to_dict(),
                    100,
                    Config.CACHE_TTL['messages'],
                    chat_id
                )
                logger.debug(f"消息数据写入缓存列表: chat={chat_id}, msg={message_id}")
            
            return message
            
        except Exception as e:
            logger.error(f"获取消息数据失败 {message_id}: {str(e)}")
            return None
    # 处理未读聊天室消息（跳过机器人消息）
    async def _process_unread_messages(self, dialog):
        """处理未读消息（跳过机器人消息）"""
        try:
            entity = await self.client.get_entity(dialog.id)
            
            processed = 0
            skipped_bot = 0
            
            async for msg in self.client.iter_messages(
                entity,
                limit=dialog.unread_count,
                min_id=dialog.message.id - dialog.unread_count if dialog.message else 0
            ):
                # 检查是否为机器人消息
                if await self._is_bot_message(msg):
                    skipped_bot += 1
                    continue
                
                # 1. 先检查缓存，再检查数据库
                existing_msg = await self._get_cached_message(msg.id, dialog.id)
                if existing_msg:
                    logger.debug(f"消息已存在，跳过: msg_id={msg.id}, chat_id={dialog.id}")
                    continue
                
                await self._process_message(msg, dialog.id)
                processed += 1
            
            return {'processed': processed, 'skipped_bot': skipped_bot}
                
        except Exception as e:
            logger.error(f"处理未读消息失败 {dialog.id}: {str(e)}")
            return {'processed': 0, 'skipped_bot': 0}
    # 检查新消息（跳过机器人消息）
    async def _check_new_messages(self):
        """检查新消息（跳过机器人消息）"""
        try:
            # 获取最后同步的消息ID（从缓存）
            last_sync_time = await self.cache.get("last_sync_time")
            
            if not last_sync_time:
                last_sync_time = int(time.time()) - 300  # 5分钟前
            
            # 检查每个聊天的新消息
            processed_count = 0
            skipped_bot_count = 0
            
            async for dialog in self.client.iter_dialogs(limit=50):
                if dialog.message and dialog.message.date:
                    msg_time = dialog.message.date.timestamp()
                    if msg_time > last_sync_time:
                        result = await self._process_chat_messages(dialog)
                        processed_count += result.get('processed', 0)
                        skipped_bot_count += result.get('skipped_bot', 0)
            
            # 更新最后同步时间
            await self.cache.set("last_sync_time", int(time.time()),3600)
            
            logger.debug(f"检查新消息完成: 处理 {processed_count} 条，跳过 {skipped_bot_count} 条机器人消息")
            
        except Exception as e:
            logger.error(f"检查新消息失败: {str(e)}")
    # 处理聊天消息（跳过机器人消息）
    async def _process_chat_messages(self, dialog):
        """处理聊天消息（跳过机器人消息）"""
        try:
            entity = await self.client.get_entity(dialog.id)
            
            # 获取上次同步后的消息
            last_msg_id = await self.cache.get_hash("chat_last_msg", str(dialog.id))
            min_id = int(last_msg_id) if last_msg_id else 0
            
            processed = 0
            skipped_bot = 0
            
            async for msg in self.client.iter_messages(
                entity,
                min_id=min_id,
                limit=50
            ):
                # 检查是否为机器人消息
                if await self._is_bot_message(msg):
                    skipped_bot += 1
                    continue
                
                # 1. 先检查缓存，再检查数据库
                existing_msg = await self._get_cached_message(msg.id, dialog.id)
                if existing_msg:
                    logger.debug(f"消息已存在，跳过: msg_id={msg.id}, chat_id={dialog.id}")
                    # 仍然更新最后消息ID
                    await self.cache.set_hash("chat_last_msg", str(dialog.id), msg.id)
                    continue
                
                await self._process_message(msg, dialog.id)
                processed += 1
                
                # 更新最后消息ID
                await self.cache.set_hash("chat_last_msg", str(dialog.id), msg.id)
            
            return {'processed': processed, 'skipped_bot': skipped_bot}
                
        except Exception as e:
            logger.error(f"处理聊天消息失败 {dialog.id}: {str(e)}")
            return {'processed': 0, 'skipped_bot': 0}
    # 检查消息是否来自机器人
    async def _is_bot_message(self, msg):
        """检查消息是否来自机器人"""
        try:
            if not msg.sender:
                return False
            
            # 如果发送者是机器人，直接返回True
            if hasattr(msg.sender, 'bot') and msg.sender.bot:
                return True
            
            # 如果消息来自群组，检查发送者是否为机器人
            if msg.sender_id:
                try:
                    sender = await self.client.get_entity(msg.sender_id)
                    if hasattr(sender, 'bot') and sender.bot:
                        return True
                except:
                    pass
            
            # 检查消息内容是否包含机器人特征
            if msg.text:
                bot_keywords = ['/start', '/help', '/settings', 'bot', 'Bot', 'BOT']
                if any(keyword in msg.text for keyword in bot_keywords):
                    # 进一步检查是否是命令格式
                    if msg.text.startswith('/'):
                        return True
            
            return False
        except Exception as e:
            logger.debug(f"检查机器人消息失败: {str(e)}")
            return False
    # 处理消息（只处理非机器人消息）
    async def _process_message(self, msg, chat_id):
        """处理消息（只处理非机器人消息）"""
        try:
            # 检查是否为机器人消息，如果是则跳过
            if await self._is_bot_message(msg):
                logger.debug(f"跳过机器人消息: chat={chat_id}, msg={msg.id}")
                return
            
            # 1. 先检查缓存，再检查数据库
            existing_msg = await self._get_cached_message(msg.id, chat_id)
            if existing_msg:
                logger.debug(f"消息已存在，跳过: msg_id={msg.id}, chat_id={chat_id}")
                return
            
            # 下载媒体文件
            media_path = None
            if msg.media:
                # 检查媒体文件是否已下载
                media_path = await self._download_media(msg, chat_id)
                
            #logger.info(f"消息内容: msg={msg}")
            '''
            logger.info(f"消息结构分析 - 私聊: {chat_id}")
            logger.info(f"  msg.sender: {msg.sender}")
            logger.info(f"  msg.sender_id: {msg.sender_id}")
            logger.info(f"  msg.from_id: {msg.from_id}")
            logger.info(f"  msg.peer_id: {msg.peer_id}")
            logger.info(f"  msg.out: {msg.out}")  # 是否是发送的消息
            logger.info(f"  dir(msg): {[x for x in dir(msg) if not x.startswith('_')]}")
            '''
            # 获取发送者名称
            sender_name = await self._get_sender_name(msg)
            
            # 获取消息类型
            message_type = self._get_message_type(msg)
            
            # 创建消息模型
            message_model = MessageModel(
                message_id=msg.id,
                chat_id=chat_id,
                account_id=self.account_id,
                sender_id=msg.sender_id,
                sender_name=sender_name,
                message_text=msg.text or "",
                message_type=message_type,
                media_path=media_path,
                is_outgoing=msg.out,
                reply_to_msg_id=msg.reply_to.reply_to_msg_id if msg.reply_to else None,
                timestamp=msg.date,
                is_read=msg.out or (hasattr(msg, 'unread') and not msg.unread)  # 兼容性处理
            )
            
            # 保存到数据库
            await self.db.save_message(message_model)
            
            # 添加到消息列表缓存
            await self.cache.add_to_list(
                "messages",
                message_model.to_dict(),
                100,
                Config.CACHE_TTL['messages'],
                chat_id
            )
            
            # 更新聊天最后消息
            await self.cache.set_hash(
                "chat_last_msg",
                str(chat_id),
                msg.id
            )
            
            # 触发事件
            for handler in self.handlers['message_sync']:
                await handler({
                    'type': 'message_synced',
                    'message': message_model.to_dict(),
                    'account_id': self.account_id
                })
                
            logger.debug(f"保存消息: chat={chat_id}, msg={msg.id}, sender={sender_name}")
                
        except Exception as e:
            logger.error(f"处理消息失败 {msg.id}: {str(e)}")
         
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
            # 更新聊天室缓存列表
            chats_to_refresh = []
            
            for dialog in dialogs:
                unread_count = getattr(dialog, 'unread_count', 0) or 0
                
                # 累加总未读数（只统计私聊）
                if hasattr(dialog, 'is_user') and dialog.is_user:
                    total_unread += unread_count
                
                # 提取聊天信息并更新未读数
                chat_info = await self._extract_chat_info(dialog, unread_count)
                if chat_info:
                    chats_to_update.append(chat_info)
                    # 记录需要刷新缓存的聊天ID
                    chats_to_refresh.append(chat_info['chat_id'])
            
            # 批量更新聊天未读数到数据库
            if chats_to_update:
                await self.db.batch_update_chat_unread(chats_to_update)
                
            # 刷新聊天缓存中的未读数
            await self._refresh_chat_cache_unread(chats_to_refresh)
            
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
    # 刷新聊天缓存未读数
    # ============================================
    async def _refresh_chat_cache_unread(self, chat_ids: List[str]):
        """刷新聊天缓存中的未读数"""
        try:
            for chat_id in chat_ids:
                # 获取最新的聊天数据（包含更新后的未读数）
                chat_data = await self.db.get_chat_by_id(chat_id, self.account_id)
                if chat_data:
                    # 更新聊天缓存
                    await self.cache.set_hash(
                        "chats",
                        chat_id,
                        chat_data.to_dict(),
                        Config.CACHE_TTL['chats']
                    )
                    logger.debug(f"[{self.account_id}] 更新聊天缓存未读数: chat_id={chat_id}, "
                               f"unread={chat_data.unread_count}")
                    
            # 同时刷新整个聊天列表缓存
            await self._refresh_chat_list_cache()
            
        except Exception as e:
            logger.error(f"[{self.account_id}] 刷新聊天缓存未读数失败: {str(e)}")
    
    # ============================================
    # 刷新聊天列表缓存
    # ============================================
    async def _refresh_chat_list_cache(self):
        """刷新聊天列表缓存"""
        try:
            # 从数据库获取最新的聊天列表
            chats = await self.db.get_chats(
                account_id=self.account_id, 
                limit=100, 
                offset=0
            )
            logger.info(f"[{self.account_id}] 刷新聊天列表: {len(chats)}  聊天")
            if chats:
                # 转换为字典列表
                chat_dicts = [chat.to_dict() for chat in chats]
                
                # 更新缓存
                await self.cache.set_list(
                    "chat_list",
                    chat_dicts,
                    Config.CACHE_TTL['chats'],
                    account_id=self.account_id
                )
                
                logger.debug(f"[{self.account_id}] 刷新聊天列表缓存: {len(chat_dicts)} 个聊天")
                
                # 触发聊天列表更新事件
                for handler in self.handlers['chat_update']:
                    await handler({
                        'type': 'chat_list_updated',
                        'chat_id': self.account_id,
                        'chats': chat_dicts,
                        'timestamp': datetime.now()
                    })
                    
        except Exception as e:
            logger.error(f"[{self.account_id}] 刷新聊天列表缓存失败: {str(e)}")
        
            
            
            
    # ============================================
    # 安全封装方法
    # ============================================
    async def _safe_iter_dialogs(self, limit=100):
        """安全获取dialogs（带重试机制）"""
        async with self._dlg_lock:
            for attempt in range(3):
                try:
                    dialogs = await self.client.get_dialogs(
                        limit=limit,
                        ignore_migrated=True
                    )
                    return dialogs
    
                except FloodWaitError as e:
                    logger.warning(f"[{self.account_id}] iter_dialogs FloodWait {e.seconds}s")
                    await asyncio.sleep(e.seconds)
    
                except Exception as e:
                    logger.warning(f"[{self.account_id}] iter_dialogs失败({attempt+1}/3): {e}")
                    await asyncio.sleep(1.5 * (attempt + 1))
        
        logger.error(f"[{self.account_id}] iter_dialogs 失败超过 3 次")
        return []
    # ============================================
    # 联系人获取方法（优化版）
    # ============================================
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
    # 缓存更新方法
    # ============================================
    async def _update_caches(self):
        """更新缓存"""
        try:
            # 更新账号信息缓存
            account_info = await self.db.get_account_info(self.account_id)
            if account_info:
                await self.cache.set(
                    "account_info",
                    account_info.to_dict(),
                    Config.CACHE_TTL['account_info']
                )
            
            # 更新群组列表缓存
            groups = await self.db.get_groups(limit=50, account_id=self.account_id)
            if groups:
                await self.cache.set(
                    "groups",
                    [group.to_dict() for group in groups],
                    Config.CACHE_TTL['groups']
                )
            
            # 更新用户列表缓存（过滤掉机器人）
            users = await self.db.get_users(limit=100, account_id=self.account_id)
            non_bot_users = [user for user in users if not user.is_bot]
            if non_bot_users:
                await self.cache.set(
                    "users",
                    [user.to_dict() for user in non_bot_users],
                    Config.CACHE_TTL['user']
                )
                
        except Exception as e:
            logger.error(f"更新缓存失败: {str(e)}")
    # ============================================
    # 新消息处理方法（支持多种聊天类型）
    # ============================================
    async def _handle_new_message(self, event):
        """处理新消息事件（支持私聊、群组、频道等）"""
        try:
            message = event.message
            
            # 检查是否为机器人消息，如果是则跳过
            if await self._is_bot_message(message):
                logger.debug(f"跳过机器人新消息: msg={message.id}")
                return
            
            # 1. 分析消息获取聊天类型和ID
            chat_info = await self._analyze_message_chat_info(message)
            if not chat_info:
                logger.error(f"无法分析聊天信息: msg_id={message.id}")
                return
            #
            newchat_id = chat_info['newchat_id'] 
            oldchat_id = chat_info['oldchat_id']
            #类型
            chat_type = chat_info['chat_type']
            
            logger.info(f"新消息分析结果: msg={message.id}, 最新chat_id={newchat_id}, "
                       f"原始ID={oldchat_id}, type={chat_type}")
            
            # 2. 检查消息是否已存在
            existing_msg = await self._get_cached_message(message.id, newchat_id)
            if existing_msg:
                logger.debug(f"新消息已存在，跳过: msg_id={message.id}, chat_id={newchat_id}")
                return
            
            # 3. 获取或创建聊天室记录
            existing_chat = await self._get_cached_chat(newchat_id)
            chat_created = False
            
            if not existing_chat:
                logger.info(f"聊天室不存在，创建聊天室: chat_id={newchat_id}")
                chat_created = True
                
                try:
                    # 尝试获取实体信息来创建聊天记录
                    entity = None
                    try:
                        entity = await self.client.get_entity(oldchat_id)
                    except Exception as e:
                        logger.warning(f"获取实体失败 {oldchat_id}: {str(e)}")
                        # 如果获取失败，使用基本信息
                        entity = type('Entity', (), {
                            'id': oldchat_id,
                            'title': f"{chat_type}_{oldchat_id}",
                            'username': None,
                            'photo': None
                        })()
                    
                    # 确定聊天类型（再次确认）
                    final_chat_type = chat_type
                    if chat_type == 'channel' and hasattr(entity, 'megagroup') and entity.megagroup:
                        final_chat_type = 'supergroup'
                    
                    # 获取头像路径
                    avatar_path = None
                    if final_chat_type == 'private' and hasattr(entity, 'photo'):
                        avatar_path = await self._download_user_avatar(entity)
                    elif final_chat_type in ['group', 'supergroup', 'channel'] and hasattr(entity, 'photo'):
                        avatar_path = await self._download_group_avatar(entity)
                    
                    # 创建聊天模型
                    chat_model = ChatModel(
                        chat_id=newchat_id,
                        account_id=self.account_id,
                        chat_type=final_chat_type,
                        title=getattr(entity, 'title', f'{final_chat_type}_{oldchat_id}'),
                        unread_count=0,  # 初始未读数为0
                        last_message_id=message.id,
                        last_message_time=message.date,
                        avatar_path=avatar_path,
                        participants_count=getattr(entity, 'participants_count', 0) if hasattr(entity, 'participants_count') else 0,
                        username=getattr(entity, 'username', None),
                        is_bot=getattr(entity, 'bot', False) if final_chat_type == 'private' else False
                    )
                    
                    # 保存到数据库
                    await self.db.save_chat(chat_model)
                    logger.info(f"创建聊天室成功: {newchat_id} ({final_chat_type})")
                    
                except Exception as e:
                    logger.error(f"创建聊天室失败: {str(e)}")
            
            # 4. 处理消息
            await self._process_message_with_type(message, newchat_id, chat_info)
            
            # 5. 更新聊天最后消息信息
            await self.db.update_chat_last_message(
                chat_id=newchat_id,
                account_id=self.account_id,
                last_message_id=message.id,
                last_message_time=message.date
            )
            
            # 6. 更新聊天未读数（如果是接收的消息且不是频道帖子）
            if not message.out and chat_type != 'channel':
                await self.db.increment_chat_unread(newchat_id, self.account_id)
            
            # 7. 更新缓存中的聊天信息
            await self._update_chat_cache_after_new_message(newchat_id)
            
            # 8. 触发事件
            for handler in self.handlers['new_message']:
                await handler({
                    'type': 'new_message',
                    'message_data': {
                        'id': message.id,
                        'chat_id': newchat_id,
                        'oldchat_id': oldchat_id,
                        'chat_type': chat_type,
                        'text': message.text,
                        'sender_id': await self._get_message_sender_id(message),
                        'sender_name': await self._get_sender_name(message),
                        'timestamp': message.date,
                        'is_outgoing': message.out,
                        'is_bot': False,
                        'is_channel_post': getattr(message, 'post', False) if chat_type == 'channel' else False,
                        'message': message
                    },
                    'account_id': self.account_id
                })
            
            logger.info(f"新消息处理完成: msg={message.id}, chat={newchat_id}, type={chat_type}")
                    
        except Exception as e:
            logger.error(f"处理新消息事件失败: {str(e)}", exc_info=True)
    
    # ============================================
    # 新增：消息聊天信息分析
    # ============================================
    async def _analyze_message_chat_info(self, message):
        """分析消息的聊天信息"""
        try:
            # 分析 peer_id 获取原始聊天ID和类型
            peer = message.peer_id
            
            if hasattr(peer, 'user_id'):
                # 私聊
                oldchat_id = peer.user_id
                chat_type = 'private'
                newchat_id = f"{oldchat_id}"
                
            elif hasattr(peer, 'chat_id'):
                # 普通群组
                oldchat_id = peer.chat_id
                chat_type = 'group'
                newchat_id = f"-{oldchat_id}"
                
            elif hasattr(peer, 'channel_id'):
                # 频道或超级群组
                oldchat_id = peer.channel_id
                # 需要判断是频道还是超级群组
                try:
                    entity = await self.client.get_entity(oldchat_id)
                    if hasattr(entity, 'megagroup') and entity.megagroup:
                        chat_type = 'supergroup'
                        newchat_id = f"-100{abs(oldchat_id)}" if oldchat_id < 0 else f"-100{oldchat_id}"
                    else:
                        chat_type = 'channel'
                        newchat_id = f"-100{abs(oldchat_id)}" if oldchat_id < 0 else f"-100{oldchat_id}"
                except Exception as e:
                    logger.warning(f"获取频道实体失败，默认按频道处理: {oldchat_id}, {str(e)}")
                    chat_type = 'channel'
                    newchat_id = f"-100{abs(oldchat_id)}" if oldchat_id < 0 else f"-100{oldchat_id}"
            
            else:
                logger.error(f"未知的peer类型: {type(peer)}")
                return None
            
            # 获取发送者信息
            sender_id = None
            if hasattr(message, 'from_id'):
                from_id = message.from_id
                if hasattr(from_id, 'user_id'):
                    sender_id = from_id.user_id
                elif hasattr(from_id, 'channel_id'):
                    sender_id = from_id.channel_id
            
            return {
                'newchat_id': newchat_id,
                'oldchat_id': oldchat_id,
                'chat_type': chat_type,
                'peer': peer,
                'sender_id': sender_id,
                'is_outgoing': getattr(message, 'out', False),
                'is_post': getattr(message, 'post', False) if chat_type == 'channel' else False
            }
            
        except Exception as e:
            logger.error(f"分析消息聊天信息失败: {str(e)}")
            return None
    
    # ============================================
    # 新增：按类型处理消息
    # ============================================
    async def _process_message_with_type(self, message, newchat_id, chat_info):
        """根据聊天类型处理消息"""
        try:
            # 检查是否为机器人消息，如果是则跳过
            if await self._is_bot_message(message):
                logger.debug(f"跳过机器人消息: chat={newchat_id}, msg={message.id}")
                return
            
            # 检查消息是否已存在
            existing_msg = await self._get_cached_message(message.id, newchat_id)
            if existing_msg:
                logger.debug(f"消息已存在，跳过: msg_id={message.id}, chat_id={newchat_id}")
                return
            
            # 下载媒体文件
            media_path = None
            if message.media:
                media_path = await self._download_media(message, newchat_id)
            
            # 获取发送者名称
            sender_name = await self._get_sender_name(message)
            
            # 获取消息类型
            message_type = self._get_message_type(message)
            
            # 创建消息模型
            message_model = MessageModel(
                message_id=message.id,
                chat_id=newchat_id,
                account_id=self.account_id,
                sender_id=chat_info['sender_id'],
                sender_name=sender_name,
                message_text=message.text or "",
                message_type=message_type,
                media_path=media_path,
                is_outgoing=message.out,
                reply_to_msg_id=message.reply_to.reply_to_msg_id if message.reply_to else None,
                timestamp=message.date,
                is_read=message.out or (hasattr(message, 'unread') and not message.unread),  # 兼容性处理
                
            )
            
            # 保存到数据库
            await self.db.save_message(message_model)
            
            # 添加到消息列表缓存
            await self.cache.add_to_list(
                "messages",
                message_model.to_dict(),
                100,
                Config.CACHE_TTL['messages'],
                newchat_id
            )
            
            # 更新聊天最后消息
            await self.cache.set_hash(
                "chat_last_msg",
                str(newchat_id),
                message.id
            )
            
            # 触发事件
            for handler in self.handlers['message_sync']:
                await handler({
                    'type': 'message_synced',
                    'message': message_model.to_dict(),
                    'account_id': self.account_id
                })
                
            logger.debug(f"保存消息: chat={newchat_id}, msg={message.id}, "
                       f"type={chat_info['chat_type']}, sender={sender_name}")
                
        except Exception as e:
            logger.error(f"处理消息失败 {message.id}: {str(e)}")
    
    # ============================================
    # 新增：获取消息发送者ID
    # ============================================
    async def _get_message_sender_id(self, message):
        """获取消息发送者ID"""
        try:
            if hasattr(message, 'from_id'):
                from_id = message.from_id
                if hasattr(from_id, 'user_id'):
                    return from_id.user_id
                elif hasattr(from_id, 'channel_id'):
                    return from_id.channel_id
            
            if hasattr(message, 'sender_id'):
                sender = message.sender_id
                if hasattr(sender, 'user_id'):
                    return sender.user_id
            
            return None
        except Exception as e:
            logger.debug(f"获取消息发送者ID失败: {str(e)}")
            return None
            
    # ============================================
    # 更新单个聊天未读数
    # ============================================
    async def _update_chat_unread_count(self, chat_id: str):
        """更新单个聊天的未读数"""
        try:
            # 从数据库获取当前未读数
            chat_data = await self.db.get_chat_by_id(chat_id, self.account_id)
            if chat_data:
                # 获取最新的对话框信息
                dialogs = await self._safe_iter_dialogs(limit=10)
                for dialog in dialogs:
                    dialog_id = getattr(dialog.entity, 'id', None)
                    if dialog_id and str(dialog_id) in chat_id:
                        # 更新未读数
                        new_unread = getattr(dialog, 'unread_count', 0) or 0
                        
                        # 如果未读数有变化，更新数据库和缓存
                        if chat_data.unread_count != new_unread:
                            chat_data.unread_count = new_unread
                            chat_data.updated_at = datetime.now()
                            
                            # 更新数据库 - 使用更新方法而不是保存
                            await self.db.update_chat_unread(
                                chat_id=chat_id,
                                account_id=self.account_id,
                                unread_count=new_unread,
                                last_message_time=chat_data.last_message_time
                            )
                            
                            # 更新缓存
                            await self.cache.set_hash(
                                "chats",
                                chat_id,
                                chat_data.to_dict(),
                                Config.CACHE_TTL['chats']
                            )
                            
                            logger.debug(f"[{self.account_id}] 更新聊天未读数: "
                                       f"chat_id={chat_id}, unread={new_unread}")
                        break
                        
        except Exception as e:
            logger.error(f"[{self.account_id}] 更新聊天未读数失败 {chat_id}: {str(e)}")   
    # ============================================
    # 新增方法：新消息后更新缓存
    # ============================================
    async def _update_chat_cache_after_new_message(self, chat_id: str):
        """新消息后更新缓存"""
        try:
            # 从数据库获取更新后的聊天信息
            chat_data = await self.db.get_chat_by_id(chat_id, self.account_id)
            if chat_data:
                # 更新聊天缓存
                await self.cache.set_hash(
                        "chats",
                        chat_id,
                        chat_data.to_dict(),
                        Config.CACHE_TTL['chats']
                    )
                
                # 更新聊天列表缓存中的该聊天信息
                chat_list = await self.cache.get_list(
                    "chat_list",
                    account_id=self.account_id
                )
                
                if chat_list:
                    # 找到对应的聊天并更新
                    for i, chat in enumerate(chat_list):
                        if chat.get('chat_id') == chat_id:
                            chat_list[i] = chat_data.to_dict()
                            break
                    else:
                        # 如果不在列表中，添加到开头
                        chat_list.insert(0, chat_data.to_dict())
                    
                    # 保持列表长度
                    if len(chat_list) > 100:
                        chat_list = chat_list[:100]
                    
                    # 更新缓存
                    await self.cache.set_list(
                        "chat_list",
                        chat_list,
                        Config.CACHE_TTL['chat_list'],
                        account_id=self.account_id
                    )
                    
                logger.debug(f"[{self.account_id}] 新消息后更新聊天缓存: chat_id={chat_id}")
                
        except Exception as e:
            logger.error(f"[{self.account_id}] 更新聊天缓存失败 {chat_id}: {str(e)}")        
    # ============================================
    # 聊天更新处理方法（跳过机器人聊天更新）
    # ============================================
    async def _handle_chat_update(self, event):
        """处理聊天更新事件（跳过机器人聊天更新）"""
        try:
            chat = await event.get_chat()
            user = await event.get_user()
            
            # 跳过机器人相关的聊天更新
            if user and hasattr(user, 'bot') and user.bot:
                logger.debug(f"跳过机器人聊天更新: user={user.id}")
                return
            
            # 更新聊天信息
            if chat:
                await self._process_chat_update(chat)
            
            # 触发事件
            for handler in self.handlers['chat_update']:
                await handler({
                    'type': 'chat_action',
                    'action': str(event.action_message.action),
                    'chat_id': chat.id if chat else None,
                    'user_id': user.id if user else None,
                    'is_bot': user.bot if user and hasattr(user, 'bot') else False,
                    'timestamp': datetime.now(),
                    'account_id': self.account_id
                })
                
        except Exception as e:
            logger.error(f"处理聊天更新事件失败: {str(e)}")
    
    # ============================================
    # 聊天更新处理方法（跳过机器人聊天更新）
    # ============================================
    async def _process_chat_update(self, chat):
        """处理聊天更新"""
        try:
            dialog = await self.client.get_dialog(chat.id)
            
            if dialog:
                await self._process_chat(dialog)
                
        except Exception as e:
            logger.error(f"处理聊天更新失败 {chat.id}: {str(e)}")
    # ============================================
    # 用户头像下载方法（跳过机器人头像）
    # ============================================
    async def _download_user_avatar(self, user, is_account=False):
        """下载用户头像"""
        try:
            if not user.photo:
                return None
            
            # 确定存储路径
            if is_account:
                avatar_dir = os.path.join(self.avatar_dir, "account")
                filename = f"account_avatar.jpg"
            else:
                avatar_dir = os.path.join(self.avatar_dir, "users")
                filename = f"user_{user.id}.jpg"
            
            avatar_path = os.path.join(avatar_dir, filename)
            
            # 检查是否已下载且未过期
            if os.path.exists(avatar_path):
                file_time = datetime.fromtimestamp(os.path.getmtime(avatar_path))
                if datetime.now() - file_time < timedelta(days=7):
                    return avatar_path
            
            # 下载头像
            photo_bytes = await self.client.download_profile_photo(user, file=bytes)
            if photo_bytes:
                with open(avatar_path, "wb") as f:
                    f.write(photo_bytes)
                return avatar_path
            
            return None
            
        except Exception as e:
            logger.error(f"下载用户头像失败 {user.id}: {str(e)}")
            return None
    # ============================================
    # 群组头像下载方法（跳过机器人群组头像）
    # ============================================
    async def _download_group_avatar(self, group):
        """下载群组头像"""
        try:
            if not hasattr(group, 'photo') or not group.photo:
                return None
            
            avatar_dir = os.path.join(self.avatar_dir, "groups")
            filename = f"group_{group.id}.jpg"
            avatar_path = os.path.join(avatar_dir, filename)
            
            # 检查是否已下载且未过期
            if os.path.exists(avatar_path):
                file_time = datetime.fromtimestamp(os.path.getmtime(avatar_path))
                if datetime.now() - file_time < timedelta(days=7):
                    return avatar_path
            
            # 下载头像
            photo_bytes = await self.client.download_profile_photo(group, file=bytes)
            if photo_bytes:
                with open(avatar_path, "wb") as f:
                    f.write(photo_bytes)
                return avatar_path
            
            return None
            
        except Exception as e:
            logger.error(f"下载群组头像失败 {group.id}: {str(e)}")
            return None
    # ============================================
    # 媒体文件下载方法（跳过机器人媒体文件）
    # ============================================
    async def _download_media(self, message, chat_id):
        """下载媒体文件"""
        try:
            if not message.media:
                return None
            
            # 创建聊天媒体目录
            chat_media_dir = os.path.join(self.media_dir, str(chat_id))
            os.makedirs(chat_media_dir, exist_ok=True)
            
            # 检查是否已下载
            # 根据消息ID查找现有文件
            for file in os.listdir(chat_media_dir):
                if file.startswith(f"{message.id}_"):
                    file_path = os.path.join(chat_media_dir, file)
                    logger.debug(f"媒体文件已存在: {file_path}")
                    return file_path
            
            # 生成文件名
            timestamp = int(time.time())
            file_extension = self._get_media_extension(message)
            filename = f"{message.id}_{timestamp}.{file_extension}"
            media_path = os.path.join(chat_media_dir, filename)
            
            # 下载媒体文件
            await self.client.download_media(message.media, file=media_path)
            
            return media_path
            
        except Exception as e:
            logger.error(f"下载媒体文件失败 {message.id}: {str(e)}")
            return None
    # ============================================
    # 媒体文件扩展名获取方法（跳过机器人媒体文件）
    # ============================================
    def _get_media_extension(self, message):
        """获取媒体文件扩展名"""
        if hasattr(message.media, 'photo'):
            return "jpg"
        elif hasattr(message.media, 'voice'):
            return "ogg"
        elif hasattr(message.media, 'video'):
            return "mp4"
        elif hasattr(message.media, 'document'):
            # 尝试从文档获取扩展名
            doc = message.media.document
            if hasattr(doc, 'mime_type'):
                mime = doc.mime_type
                if mime == "application/pdf":
                    return "pdf"
                elif mime.startswith("image/"):
                    return "jpg"
                elif mime.startswith("video/"):
                    return "mp4"
                elif mime.startswith("audio/"):
                    return "mp3"
        return "dat"
    # ============================================
    # 聊天类型获取方法（跳过机器人聊天类型）
    # ============================================
    def _get_chat_type(self, dialog):
        """获取聊天类型"""
        if dialog.is_user:
            return "private"
        elif dialog.is_group:
            return "group"
        elif dialog.is_channel:
            entity = dialog.entity
            if isinstance(entity, Channel) and entity.megagroup:
                return "supergroup"
            else:
                return "channel"
        return "unknown"
    # ============================================
    # 发送者名称获取方法（跳过机器人发送者名称）
    # ============================================
    async def _get_sender_name(self, msg):
        """异步获取发送者名称"""
        try:
            # 1. 首先尝试 msg.sender（如果有）
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
            
            # 2. 如果没有 sender，尝试通过 client 获取
            user_id = None
            
            # 从 from_id 获取用户ID
            if hasattr(msg, 'from_id') and msg.from_id:
                
                if isinstance(msg.from_id, PeerUser):
                    user_id = msg.from_id.user_id
            
            # 从 sender_id 获取用户ID
            elif hasattr(msg, 'sender_id') and msg.sender_id:
                
                if isinstance(msg.sender_id, PeerUser):
                    user_id = msg.sender_id.user_id
                elif isinstance(msg.sender_id, int):
                    user_id = msg.sender_id
            
            # 从 peer_id 获取用户ID（私聊）
            elif hasattr(msg, 'peer_id') and msg.peer_id:
                if isinstance(msg.peer_id, PeerUser):
                    user_id = msg.peer_id.user_id
            
            if user_id:
                try:
                    # 使用 client.get_entity 异步获取用户信息
                    user = await self.client.get_entity(user_id)
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
                    logger.debug(f"异步获取用户信息失败 {user_id}: {e}")
            
            # 3. 最后返回ID作为名称
            if user_id:
                return f"User_{user_id}"
            
            return "Unknown"
            
        except Exception as e:
            logger.error(f"获取发送者名称失败: {e}")
            return "Unknown"
    # ============================================
    # 消息类型获取方法（跳过机器人消息类型）
    # ============================================
    def _get_message_type(self, msg):
        """获取消息类型"""
        if msg.media:
            if hasattr(msg.media, 'photo'):
                return "photo"
            elif hasattr(msg.media, 'voice'):
                return "voice"
            elif hasattr(msg.media, 'video'):
                return "video"
            elif hasattr(msg.media, 'document'):
                return "document"
            else:
                return "media"
        return "text"
    #从dialog中提取聊天信息  
    async def _extract_chat_info(self, dialog, unread_count=None) -> Optional[Dict]:
        """从dialog中提取聊天信息"""
        try:
            if unread_count is None:
                unread_count = getattr(dialog, 'unread_count', 0) or 0
            
            chat_info = {
                'account_id': self.account_id,
                'unread_count': getattr(dialog, 'unread_count', 0) or 0,
                'last_message_time': datetime.now(),
                'updated_at': datetime.now()
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
                    'chat_id': f"{entity.id}",
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
            
    async def _get_dialog_for_chat(self, chat):
        """获取聊天对应的对话框"""
        try:
            return await self.client.get_dialog(chat.id)
        except Exception as e:
            logger.debug(f"获取对话框失败 {chat.id}: {str(e)}")
            return None      
            
    async def _safe_get_chat(self, message):
        """安全获取聊天信息（带重试机制）"""
        for attempt in range(3):
            try:
                return await message.get_chat()
            except Exception as e:
                logger.warning(f"获取聊天信息失败({attempt+1}/3): {str(e)}")
                await asyncio.sleep(1)
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