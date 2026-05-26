# monitor.py (优化合并版)
import asyncio
import logging
import os
import time
import random
import aiomysql
import contextvars
from collections import defaultdict
from contextlib import asynccontextmanager
from typing import Dict, Any, Optional, List, Callable
from datetime import datetime, timezone, timedelta
from telethon import TelegramClient, events
from telethon.tl.functions.contacts import GetContactsRequest
from telethon.tl.types import User, Channel, Chat, PeerUser, Dialog
from telethon.errors import (
    FloodWaitError, 
    RPCError
)
from telethon.tl.functions.users import GetFullUserRequest

from telethon.tl.functions import PingRequest

from database import MySQLDatabase
from cache import RedisCache
from models import MessageModel, ChatModel, GroupMemberModel
from config import Config
from telegram_api_governor import TelegramApiGovernor

from logging_config import get_logger

logger = get_logger(__name__)

_held_telethon_conn_locks = contextvars.ContextVar("held_telethon_conn_locks", default=())


class TelegramMonitor:
    """Telegram实时监听器（不处理机器人信息）"""
    
    def __init__(self, client: TelegramClient, account_id: str, db: MySQLDatabase, cache: RedisCache, session_path: str = None, io_lock: asyncio.Lock = None, conn_lock: asyncio.Lock = None):
        self.client = client
        self.account_id = account_id
        self.db = db
        self.cache = cache
        self.session_path = session_path
        self._io_lock = io_lock
        self._conn_lock = conn_lock or asyncio.Lock()
        self._db_sem = asyncio.Semaphore(4)# 限制数据库并发
        # 状态标志
        self.is_monitoring = False
        self.monitor_task = None
        self.sync_task = None
        self.heartbeat_task = None
        self.watchdog_task = None        
        self._heartbeat_count = 0  # 心跳计数器
        
        # 缓存
        self._cached_dialogs: Optional[List[Dialog]] = None
        self._cached_dialogs_time: Optional[float] = None
        self._cached_dialogs_ttl = 60  # 缓存60秒
        self.sender_cache = {}
        self._unread_cache = defaultdict(int)
        
        # 用户信息缓存 {user_id: {'name': str, 'username': str, 'is_bot': bool, 'time': float}}
        self._user_info_cache = {}
        self._user_info_ttl = 600  # 10分钟
        self._api_governor = TelegramApiGovernor(
            min_interval=float(os.getenv("TELEGRAM_MONITOR_API_MIN_INTERVAL", "0.6")),
            entity_ttl=300,
            me_ttl=60,
        )
        self._last_dialog_invalidate_at = 0.0
        self._dialogs_invalidate_interval = 3.0
        
        # 锁
        self._dlg_lock = asyncio.Lock()
        self._contact_lock = asyncio.Lock()
        self._chat_sync_lock = asyncio.Lock()
        self._heartbeat_lock = asyncio.Lock()
        
        # 事件处理器
        self.handlers = {
            'new_message': [],
            'new_chat': [],
            'chat_update': [],
            'account_update': [],
            'participants_update': []  # 参与者更新事件
        }
        
        # 存储目录
        self._init_storage()

    @asynccontextmanager
    async def _conn_guard(self):
        """
        连接保护上下文管理器，确保同一时间只有一个协程使用连接
        """
        async with self._conn_lock:
            try:
                yield
            finally:
                pass
    async def _db_run(self, fn, *args, **kwargs):
        """执行数据库操作，带重试和并发限制"""
        last_exc = None
        for attempt in range(3):
            try:
                async with self._db_sem:
                    return await fn(*args, **kwargs)
            except aiomysql.Error as e:
                last_exc = e
                err_msg = str(e)

                errno = None
                if getattr(e, 'args', None) and isinstance(e.args, (list, tuple)) and e.args:
                    if isinstance(e.args[0], int):
                        errno = e.args[0]

                if errno in (1213, 1205) or 'Deadlock' in err_msg:
                    wait_time = random.uniform(0.1, 0.5) * (attempt + 1)
                    logger.warning(f"[{self.account_id}] DB锁冲突，第{attempt+1}次重试，等待{wait_time:.2f}秒: {e}")
                    await asyncio.sleep(wait_time)
                    continue

                if ('Too many connections' in err_msg or '1040' in err_msg or '2006' in err_msg or '2013' in err_msg):
                    wait_time = 1.5 * (attempt + 1)
                    logger.warning(f"[{self.account_id}] DB连接错误，第{attempt+1}次重试: {e}")
                    await asyncio.sleep(wait_time)
                    continue

                raise
            except Exception as e:
                last_exc = e
                raise

        if last_exc:
            raise last_exc
        return None

    async def _wait_for_api_slot(self, action="default", min_interval=None):
        await self._api_governor.wait_for_slot(action=action, min_interval=min_interval)

    async def _get_me_cached(self, force_refresh=False, ttl=None):
        async def _fetch_me():
            async with self._conn_guard():
                return await self.client.get_me()
        return await self._api_governor.get_me(
            fetcher=_fetch_me,
            force_refresh=force_refresh,
            ttl=ttl,
            action="get_me",
            min_interval=0.3,
        )

    async def _get_entity_cached(self, entity_ref, force_refresh=False, ttl=None):
        async def _fetch_entity(ref):
            if self._io_lock:
                async with self._conn_guard():
                    async with self._io_lock:
                        return await self.client.get_entity(ref)
            async with self._conn_guard():
                return await self.client.get_entity(ref)
        return await self._api_governor.get_entity(
            entity_ref=entity_ref,
            fetcher=_fetch_entity,
            force_refresh=force_refresh,
            ttl=ttl,
            action="get_entity",
            min_interval=0.35,
        )

    async def _invalidate_dialogs_cache_throttled(self, force: bool = False):
        now = time.time()
        if force or (now - self._last_dialog_invalidate_at >= self._dialogs_invalidate_interval):
            await self._invalidate_dialogs_cache()
            self._last_dialog_invalidate_at = now

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
            logger.error(f"初始化存储目录失败: {e}")
    
    def add_handler(self, event_type: str, callback: Callable):
        """添加事件处理器"""
        if event_type in self.handlers:
            self.handlers[event_type].append(callback)
            logger.debug(f"添加事件处理器: {event_type}")
            
    async def _trigger_event(self, event_type: str, data: Dict[str, Any]):
        """触发事件"""
        if event_type in self.handlers:
            for handler in self.handlers[event_type]:
                try:
                    await handler(data)
                except Exception as e:
                    logger.error(f"事件处理器执行失败: {e}")
    # ==================== 启动/停止 ====================                
    async def start(self) -> Dict[str, Any]:
        """启动监听器"""
        if self.is_monitoring:
            return {"status": False, "message": "已在监听中"}
        
        try:
            
            # 设置事件处理器
            self.client.add_event_handler(
                self._handle_new_message,
                events.NewMessage(incoming=True, outgoing=True)
            )
            self.client.add_event_handler(
                self._handle_chat_action,  # 合并的聊天群动作处理器
                events.ChatAction()
            )
            
            # 启动心跳任务
            self.heartbeat_task = asyncio.create_task(self._heartbeat_loop())
            # 启动监控任务，检查心跳是否存活
            self.watchdog_task = asyncio.create_task(self._watchdog())
            # 更新状态
            self.is_monitoring = True
            
            # 执行合并的全量同步
            #await self._unified_full_sync()
            
            # 触发事件
            await self._trigger_event('account_update', {
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
                    "start_time": datetime.now().isoformat()
                }
            }
            
        except Exception as e:
            logger.error(f"启动监听器失败: {e}")
            return {
                "status": False,
                "message": f"启动监听器失败: {e}"
            }
    
    async def stop(self) -> Dict[str, Any]:
        """停止监听器"""
        try:
            self.is_monitoring = False
            
            # 清除对话框缓存
            self._cached_dialogs = None
            self._cached_dialogs_time = None
            self._user_info_cache.clear()
            self._api_governor.clear()
            
            # 停止所有任务
            tasks = []
            if self.heartbeat_task:
                tasks.append(self._cancel_task(self.heartbeat_task, "心跳任务"))
            if self.monitor_task:
                tasks.append(self._cancel_task(self.monitor_task, "监听任务"))
            if self.sync_task:
                tasks.append(self._cancel_task(self.sync_task, "同步任务"))
            if self.watchdog_task:
                tasks.append(self._cancel_task(self.watchdog_task, "同步任务"))    
            
            # 等待所有任务取消完成
            if tasks:
                await asyncio.gather(*tasks, return_exceptions=True)
            
            # 移除事件处理器
            try:
                self.client.remove_event_handler(self._handle_new_message)
                self.client.remove_event_handler(self._handle_chat_action)
            except Exception:
                pass
            
            # 触发事件
            await self._trigger_event('account_update', {
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
            logger.error(f"停止监听器失败: {e}")
            return {
                "status": False,
                "message": f"停止监听器失败: {e}"
            }
    
    async def _cancel_task(self, task, task_name: str):
        """安全取消任务"""
        try:
            task.cancel()
            await asyncio.wait_for(task, timeout=3.0)
            logger.debug(f"{task_name}已取消")
        except (asyncio.TimeoutError, asyncio.CancelledError):
            logger.debug(f"{task_name}已取消")
        except Exception as e:
            logger.warning(f"取消{task_name}异常: {e}")

    # ============================================
    # 心跳管理
    # ============================================
    
    async def _heartbeat_loop(self):
        """心跳循环"""
        while self.is_monitoring:
            try:
                random_sleep = random.uniform(30, 45)
                await asyncio.sleep(random_sleep)
                
                if not self.is_monitoring:
                    break
                
                # 发送心跳
                try:
                    ok = await self._send_heartbeat()
                    if ok is False:
                        await self._attempt_reconnect("heartbeat_timeout")
                except Exception as e:
                    logger.warning(f"心跳发送失败，尝试重新连接: {e}")
                    await self._attempt_reconnect(str(e))

                # 周期性全面检查 (每20次心跳，约10-13分钟)
                self._heartbeat_count += 1
                if self._heartbeat_count >= 60:
                    self._heartbeat_count = 0
                    await self._check_account_status()
                    '''
                    try:
                        await self._catch_up_sync(dialog_limit=100)
                    except Exception as e:
                        logger.warning(f"[{self.account_id}] 周期性补偿同步失败: {e}")
                    '''
            except asyncio.CancelledError:
                break
            except Exception as e:
                logger.error(f"心跳错误: {e}")
                await asyncio.sleep(5)

    async def _send_heartbeat(self):
        """发送心跳"""
        try:
            timestamp = int(time.time())
            random_suffix = random.randint(0, 9999)  # 4位随机数
            ping_id = timestamp * 10000 + random_suffix
            
            try:
                if self._io_lock:
                    async with self._conn_guard():
                        async with self._io_lock:
                            response = await self.client(PingRequest(ping_id=ping_id))
                else:
                    async with self._conn_guard():
                        response = await self.client(PingRequest(ping_id=ping_id))
                # 成功时仅在调试级别记录
                logger.info(f"心跳成功:ping_id={ping_id},response={response}, account={self.account_id}")
                return True
                
            except asyncio.TimeoutError:
                logger.warning(f"心跳超时: account={self.account_id}")
                return False
        except RPCError as e:
            err_msg = str(e)
            if "USER_BANNED" in err_msg or "USER_DEACTIVATED" in err_msg:
                status = "封号" if "USER_BANNED" in err_msg else "注销"
                logger.error(f"账号{status}: {e}")
                await self._update_status(status, err_msg)
                raise e
            elif "AUTH_KEY" in err_msg:
                status = "退出" if "UNREGISTERED" in err_msg else "未授权"
                logger.error(f"账号{status}: {e}")
                await self._update_status(status, err_msg)
                raise e
            else:
                logger.warning(f"心跳RPC错误: {e}")
                raise e        
        except Exception as e:
            logger.warning(f"心跳失败: {e}")
            err_msg = str(e)
            # 检查连接/代理错误
            if any(x in err_msg for x in ["Proxy", "Connection", "OSError", "readexactly"]):
                await self._update_status("代理异常", err_msg)
                if "readexactly" in err_msg or "Connection" in err_msg:
                    raise e
            # 检查AuthKey错误 (如果不是RPCError)
            elif "AuthKey" in err_msg:
                status = "退出" if "Unregistered" in err_msg else "未授权"
                await self._update_status(status, err_msg)
                raise e
            else:
                if "readexactly" in err_msg or "Connection" in err_msg:
                    await self._update_status("异常", f"连接严重错误: {e}")
                    raise e
    async def _attempt_reconnect(self, reason: str):
        """尝试重连并补偿同步"""
        try:
            if not self.is_monitoring:
                return

            connected = self._check_client_status()
            if connected and reason != "heartbeat_timeout":
                return

            for attempt in range(3):
                need_catch_up = False
                try:
                    async with self._conn_guard():
                        if not self.is_monitoring:
                            return

                        if self._io_lock:
                            async with self._io_lock:
                                try:
                                    await self.client.disconnect()
                                except Exception:
                                    pass
                                await self.client.connect()
                        else:
                            try:
                                await self.client.disconnect()
                            except Exception:
                                pass
                            await self.client.connect()

                        timestamp = int(time.time())
                        random_suffix = random.randint(0, 9999)
                        ping_id = timestamp * 10000 + random_suffix
                        await self.client(PingRequest(ping_id=ping_id))
                        need_catch_up = True

                    if need_catch_up:
                        await self._invalidate_dialogs_cache()
                        await self._catch_up_sync(dialog_limit=100)
                        logger.info(f"[{self.account_id}] 重连并补偿同步完成: reason={reason}")
                        return
                except Exception as e:
                    logger.warning(f"[{self.account_id}] 重连失败({attempt+1}/3): {e}")
                    await asyncio.sleep(2.5 * (attempt + 1))
        except Exception as e:
            logger.error(f"[{self.account_id}] 重连流程异常: {e}", exc_info=True)
    
    async def _check_account_status(self):
        """详细检查账号状态（包括冻结检测）"""
        try:                      
            # 2. 获取自身信息
            async with self._conn_guard():
                if self._io_lock:
                    async with self._io_lock:
                        me = await self.client.get_me()
                else:
                    me = await self.client.get_me()
            if not me:
                logger.info(f"无法获取自身信息: {self.account_id}")
                await self._update_status("异常", "无法获取自身信息")
                return
                
            # 3. 检查是否受限
            if getattr(me, 'restricted', False):
                reasons = getattr(me, 'restriction_reason', [])
                reason_text = ', '.join([r.reason for r in reasons]) if reasons else "未知原因"
                await self._update_status("封号", f"账号受限: {reason_text}")
                return

            # 4. 检查 bot_verification (冻结标记)
            #full_user = await self.client(GetFullUserRequest(me.id))
            async with self._conn_guard():
                if self._io_lock:
                    async with self._io_lock:
                        full_user = await self.client(GetFullUserRequest(me.id))
                else:
                    full_user = await self.client(GetFullUserRequest(me.id))
                
            if (hasattr(full_user, 'full_user') and 
                hasattr(full_user.full_user, 'bot_verification') and 
                full_user.full_user.bot_verification):
                
                bot_ver = full_user.full_user.bot_verification
                description = getattr(bot_ver, 'description', '')
                if description and 'frozen' in description.lower():
                     await self._update_status("冻结", f"账号被冻结: {description}")
                     return

            # 5. 一切正常
            #logger.info(f"详细状态检查通过: {self.account_id}")
            
        except RPCError as e:
            err_msg = str(e)
            if "USER_BANNED" in err_msg or "USER_DEACTIVATED" in err_msg:
                status = "封号" if "USER_BANNED" in err_msg else "注销"
                await self._update_status(status, err_msg)
            else:
                logger.warning(f"详细状态检查RPC错误: {e}")
        except Exception as e:
            logger.warning(f"详细状态检查失败: {e}")

    async def _update_status(self, status: str, desc: str):
        """更新账号状态到数据库"""
        try:
            if self.session_path:
                # 状态映射到 online 字段 (1=在线, 0=离线/异常)
                # 只要不是正常，都视为不完全在线？或者保持原状？
                # 通常异常状态应设为离线(0)
               
                online = 1 if status == "正常" else 0
                await self._db_run(self.db.update_mtuser_status_by_session,
                    self.session_path,
                    online,
                    status,
                    desc
                )
        except Exception as e:
            logger.error(f"更新状态失败: {e}")
                    
    async def _watchdog(self):
        """监视心跳任务状态"""
        while self.is_monitoring:
            await asyncio.sleep(60)  # 每60秒检查一次
            
            if self.heartbeat_task and self.heartbeat_task.done():
                logger.warning(f"心跳任务已停止，重新启动: {self.account_id}")
                try:
                    self.heartbeat_task.result()  # 获取异常信息
                except Exception as e:
                    logger.error(f"心跳任务异常: {e}")
                
                # 重新启动心跳
                self.heartbeat_task = asyncio.create_task(self._heartbeat_loop())
  
    # ==================== 对话框缓存 ====================    
    async def _get_cached_dialogs(self, force_refresh: bool = False, limit: int = 100) -> List[Dialog]:
        """获取缓存的对话框，减少API调用"""
        current_time = time.time()
        
        # 检查缓存是否有效
        if (not force_refresh and 
            self._cached_dialogs is not None and 
            self._cached_dialogs_time is not None and
            current_time - self._cached_dialogs_time < self._cached_dialogs_ttl):
            logger.debug(f"使用缓存的对话框: {len(self._cached_dialogs)} 个")
            return self._cached_dialogs[:limit] if limit < len(self._cached_dialogs) else self._cached_dialogs
        
        # 需要刷新缓存
        try:
            dialogs = await self._safe_get_dialogs(limit)
            if dialogs:
                self._cached_dialogs = dialogs
                self._cached_dialogs_time = current_time
                logger.debug(f"刷新对话框缓存: {len(dialogs)} 个")
            return dialogs
        except Exception as e:
            logger.error(f"获取对话框失败: {e}")
            return self._cached_dialogs or []
   
    # ==================== 核心同步 ====================
    async def _catch_up_sync(self, dialog_limit: int = 100):
        """增量同步（供重连后调用）"""
        try:
            if not self.is_monitoring:
                return
            async with self._chat_sync_lock:
                dialogs = await self._get_cached_dialogs(force_refresh=True, limit=dialog_limit)
                if not dialogs:
                    return
                await self._sync_dialogs(dialogs, is_full_sync=False)
        except Exception as e:
            logger.error(f"[{self.account_id}] 补偿同步失败: {e}")

    async def _unified_full_sync(self):
        """统一全量同步 - 合并聊天同步、消息同步和未读数统计"""
        try:
            logger.info(f"开始全量同步: {self.account_id}")
            
            # 获取对话框（会缓存）
            dialogs = await self._get_cached_dialogs(force_refresh=True, limit=100)
            
            if not dialogs:
                logger.warning(f"全量同步: 未获取到对话框")
                return
            logger.info(f"获取到 {len(dialogs)} 个对话框")
            # 执行统一同步
            sync_stats = await self._sync_dialogs(dialogs, is_full_sync=True)
            if not sync_stats:
                return
        
            # 同步群组成员信息（只对群组和超级群组）
            #await self._sync_group_members_in_dialogs(dialogs, sync_stats)
            
            # 统计好友数量
            friend_count = await self._get_friend_count()
            logger.info(f"全量同步friend_count: {friend_count}")
            # 更新账号统计
            await self._update_account_stats(
                sync_stats['total_unread_count'],
                sync_stats['groups_count'],
                friend_count
            )
            
            logger.info(f"全量同步完成: {self.account_id}")
            
        except Exception as e:
            logger.error(f"全量同步失败: {e}")

    async def _sync_dialogs(self, dialogs: List[Dialog], is_full_sync: bool = True) -> Dict[str, Any]:
        """
        从对话框执行统一同步
        合并了聊天同步、未读消息同步和未读数统计
        """
        sync_stats = {
            'total_dialogs': len(dialogs),
            'chats_processed': 0,
            'chats_skipped': 0,
            'messages_processed': 0,
            'messages_skipped_bot': 0,
            'total_unread_count': 0,
            'groups_count': 0,
            'chats_updated': []
        }
        
        # 并行处理每个对话框
        tasks = []
        for dialog in dialogs:
            tasks.append(self._process_one_dialog(dialog, is_full_sync, sync_stats))
        
        # 等待所有对话框处理完成
        results = await asyncio.gather(*tasks, return_exceptions=True)
        
        # 处理结果
        for result in results:
            if isinstance(result, Exception):
                logger.error(f"处理对话框异常: {result}")
                continue
        
        # 批量更新聊天未读数
        if sync_stats['chats_updated']:
            await self._db_run(self.db.batch_update_chat_unread, sync_stats['chats_updated'])
            await self._refresh_chat_cache_unread([chat['chat_id'] for chat in sync_stats['chats_updated']])
        
        return sync_stats
    
    
    
    async def _process_one_dialog(self, dialog: Dialog, is_full_sync: bool, stats: Dict[str, Any]):
        """
        统一处理单个对话框
        合并聊天处理、未读消息处理和统计
        """
        try:
            unread_count = getattr(dialog, 'unread_count', 0) or 0
            chat_info = await self._extract_chat_info(dialog, unread_count)
            if not chat_info:
                logger.warning(f"无法提取聊天信息，跳过: {dialog.id}")
                stats['chats_skipped'] += 1
                return
            formatted_chat_id = chat_info['chat_id']

            # 1. 处理聊天信息
            chat_processed = await self._process_chat_info(dialog)
            if chat_processed:
                stats['chats_processed'] += 1
            else:
                stats['chats_skipped'] += 1
                return
            
            # 2. 统计未读消息总数
            # 3. 只统计私聊未读（用于账号统计）
            if dialog.is_user:
                stats['total_unread_count'] += unread_count
            
            # 4. 统计群组数量
            if dialog.is_group or dialog.is_channel:
                stats['groups_count'] += 1
                
            existing_chat = await self._get_cached_chat(formatted_chat_id)
            last_stored_id = int(getattr(existing_chat, 'last_message_id', 0) or 0) if existing_chat else 0
            dialog_last_id = int(dialog.message.id) if getattr(dialog, "message", None) and getattr(dialog.message, "id", None) else 0

            should_process_messages = (
                is_full_sync
                or unread_count > 0
                or (dialog_last_id > last_stored_id)
            )

            if should_process_messages:
                if (not is_full_sync) and unread_count == 0 and dialog_last_id > last_stored_id:
                    logger.info(
                        f"[{self.account_id}] 检测到消息缺口，执行补偿: chat={formatted_chat_id}, "
                        f"dialog_last={dialog_last_id}, db_last={last_stored_id}"
                    )
                message_stats = await self._process_messages_for_dialog(
                    dialog=dialog,
                    formatted_chat_id=formatted_chat_id,
                    is_full_sync=is_full_sync,
                    unread_count=unread_count,
                    last_stored_id=last_stored_id
                )
                stats['messages_processed'] += message_stats['processed']
                stats['messages_skipped_bot'] += message_stats['skipped_bot']
            
            # 6. 收集需要更新的聊天信息
            stats['chats_updated'].append(chat_info)
            
        except Exception as e:
            logger.error(f"处理对话框 {dialog.id} 失败: {e}")
    
    
    async def _extract_chat_info(self, dialog: Dialog, unread_count=None) -> Optional[Dict]:
        """从dialog中提取聊天信息"""
        try:
            if unread_count is None:
                unread_count = getattr(dialog, 'unread_count', 0) or 0
            
            entity = dialog.entity
            
            if isinstance(entity, User):
                # 私聊用户
                return {
                    'chat_id': f"{entity.id}",
                    'account_id': self.account_id,
                    'unread_count': unread_count,
                    'chat_type': 'private',
                    'title': self._get_user_display_name(entity),
                    'username': entity.username,
                    'participants_count': 1,
                    'last_message_time': datetime.now(),
                    'updated_at': datetime.now()
                }
                
            elif isinstance(entity, (Channel, Chat)):
                # 群组或频道
                if isinstance(entity, Channel):
                    chat_type = 'supergroup' if entity.megagroup else 'channel'
                else:
                    chat_type = 'group'
                
                title = getattr(entity, 'title', '未知群组')
                
                # 标准化聊天ID
                if isinstance(entity, Channel):
                    if entity.id < 0:
                        positive_id = abs(entity.id)
                    else:
                        positive_id = entity.id
                    newchat_id = f"-100{positive_id}"
                else:
                    newchat_id = f"-{entity.id}" if entity.id > 0 else str(entity.id)
                
                # 获取参与者数量
                #participants_count = await self._get_actual_participants_count_for_dialog(dialog)
                
                return {
                    'chat_id': newchat_id,
                    'account_id': self.account_id,
                    'unread_count': unread_count,
                    'chat_type': chat_type,
                    'title': title,
                    'username': getattr(entity, 'username', None),
                    #'participants_count': participants_count,
                    'last_message_time': datetime.now(),
                    'updated_at': datetime.now()
                }
            
            return None
            
        except Exception as e:
            logger.error(f"提取聊天信息失败: {e}")
            return None

    # ==================== 消息获取 ====================


    async def _handle_chat_action(self, event):
        """统一处理聊天动作事件（用户加入、退出、被踢等）"""
        try:
            if not self.is_monitoring:
                logger.debug("监听器已停止，跳过聊天动作处理")
                return
            
            if not self._check_client_status():
                logger.warning("客户端未连接，跳过聊天动作处理")
                return
            
            # 获取聊天和用户信息
            async with self._conn_guard():
                chat = await event.get_chat()
                user = await event.get_user()
            
            # 跳过机器人相关的参与者更新
            if user and hasattr(user, 'bot') and user.bot:
                logger.debug(f"跳过机器人聊天动作: user={user.id}")
                return
            # 分析聊天信息
            chat_info = await self._analyze_chat_info(chat)
            if not chat_info:
                return
            
            chat_id = chat_info['newchat_id']
            chat_type = chat_info['chat_type']
           
            # 跳过私聊
            if chat_type == 'private':
                return
            # 判断事件类型并处理
            is_join = getattr(event, 'user_joined', False) or getattr(event, 'user_added', False)
            is_left = getattr(event, 'user_left', False) or getattr(event, 'user_kicked', False)
            is_kicked = getattr(event, 'user_kicked', False) or getattr(event, 'user_deleted', False)
            # 获取动作类型
            action = None
            if hasattr(event, 'action_message') and event.action_message:
                action_msg = event.action_message
                if hasattr(action_msg, 'action'):
                    action = str(action_msg.action)
            
            # 全局事件去重检查
            if is_join or is_left:
                event_type = 'member_join' if is_join else 'member_left'
                
                # 使用全局去重，所有账户共享
                is_duplicate = await self.cache.check_global_event_dedup(
                    event_type=event_type,
                    chat_id=chat_info['newchat_id'],  # 使用原始聊天ID，所有账户一致
                    user_id=user.id,
                    action=action,
                    ttl=5  # 3秒去重窗口
                )
                
                if is_duplicate:
                    #logger.info(f"全局重复事件，跳过处理: chat={chat_id}, user={user.id}, account={self.account_id}")
                    return
            '''
            if is_join and user:
                # 成员加入
                await self._handle_member_join(chat, user, chat_id)
                
            elif is_left and user:
                # 成员离开
                await self._handle_member_left(chat, user, chat_id, is_kicked=is_kicked)
                
            '''    
            # 处理参与者数量变化
            if chat and not isinstance(chat, User):
                participants_change = await self._calculate_participants_change(event, user)
                if participants_change != 0:
                    await self._update_chat_participants_count(chat, participants_change, event, user)
                    
            safe_action_msg = getattr(event, 'action_message', None)
            
            safe_action = getattr(safe_action_msg, 'action', None)
            
            # 触发聊天更新事件
            await self._trigger_event('chat_update', {
                'type': 'chat_action',
                'action': str(safe_action) if safe_action else None,
                'chat_id': chat.id if chat else None,
                'chat_title': chat.title if chat else None,
                'user_id': user.id if user else None,
                'user_name': self._get_user_display_name(user) if user else None,
                'is_bot': user.bot if user and hasattr(user, 'bot') else False,
                'timestamp': datetime.now(),
                'account_id': self.account_id
            })
            
            # 使对话框缓存失效
            await self._invalidate_dialogs_cache()
                
        except Exception as e:
            logger.error(f"处理聊天动作事件失败: {e}", exc_info=True)
    
    async def _calculate_participants_change(self, event, user) -> int:
        """计算参与者数量变化"""
        try:
            action_msg = getattr(event, 'action_message', None)
            action = getattr(action_msg, 'action', None)
            
            # 处理多用户操作
            if action and hasattr(action, 'users') and action.users:
                users_count = len(action.users)
                if hasattr(action, 'type'):
                    action_type = str(action.type).lower()
                    if 'joined' in action_type:
                        return users_count
                    elif 'left' in action_type:
                        return -users_count
                # 没有类型时默认按加入处理
                return users_count
            
            # 处理单用户操作（优先使用事件标志）
            if getattr(event, 'user_joined', False) or getattr(event, 'user_added', False):
                return 1
            if getattr(event, 'user_left', False) or getattr(event, 'user_kicked', False):
                return -1
            
            # 兜底：根据action类型字符串判断
            if action:
                a_str = str(type(action)).lower()
                if 'adduser' in a_str or 'join' in a_str:
                    return 1
                if 'deleteuser' in a_str or 'left' in a_str or 'kick' in a_str:
                    return -1
            
            return 0
        except Exception:
            return 0
    
    async def _update_chat_participants_count(self, chat, change: int, event=None, user=None):
        """更新聊天参与者数量"""
        try:
            # 获取标准化的聊天ID
            chat_info = await self._analyze_chat_info(chat)
            if not chat_info:
                logger.warning(f"无法分析聊天信息，跳过参与者数量更新: {chat.id}")
                return
            
            formatted_chat_id = chat_info['newchat_id']
            chat_type = chat_info['chat_type']
            
            # 私聊不需要更新参与者数量（固定为1）
            if chat_type == 'private':
                logger.debug(f"私聊跳过参与者数量更新: {formatted_chat_id}")
                return
            
            # 1. 首先尝试从缓存获取参与者数量
            cached_count = await self._get_cached_participants_count(formatted_chat_id)
            current_count = None
            
            # 2. 如果缓存中没有，从数据库获取
            if cached_count is not None:
                current_count = cached_count
                logger.info(f"使用缓存参与者数量: {formatted_chat_id} = {current_count}")
            else:
                # 从数据库获取当前聊天信息
                chat_data = await self._db_run(self.db.get_chat_by_id, formatted_chat_id, self.account_id)
                if chat_data:
                    current_count = chat_data.participants_count or 0
                    logger.info(f"使用数据库参与者数量: {chat_data}  {formatted_chat_id} {self.account_id}= {current_count}")
                else:
                    # 聊天不存在于数据库，创建新记录
                    logger.info(f"聊天不存在于数据库，创建新记录: {formatted_chat_id}")
                    await self._create_chat_from_entity(chat, formatted_chat_id, chat_type)
                    return
            
            # 3. 计算新的参与者数量
            if current_count is not None:
                # 如果有明确的变化量
                if change != 0:
                    new_count = max(0, current_count + change)
                    logger.info(f"基于变化计算新数量: {current_count} + {change} = {new_count}")
                else:
                    # 没有变化量，重新获取实际数量
                    # 优先使用缓存，避免频繁API调用
                    new_count = await self._get_actual_participants_count(chat, use_cache=True)
                    logger.info(f"重新获取实际数量: {current_count} -> {new_count}")
                
                # 如果数量没有变化，跳过更新
                if new_count == current_count:
                    logger.info(f"参与者数量未变化，跳过更新: {formatted_chat_id}")
                    return
                
                # 4. 更新数据库
                await self._db_run(
                    self.db.update_chat_participants,
                    chat_id=formatted_chat_id,
                    account_id=self.account_id,
                    participants_count=new_count,
                    last_message_time=datetime.now()  # 更新最后消息时间
                )
                
                # 5. 更新缓存
                # 更新参与者数量专用缓存
                await self._set_cached_participants_count(formatted_chat_id, new_count)
                
                # 更新完整的聊天缓存
                if cached_count is None:  # 如果之前没有缓存，需要获取完整聊天数据
                    chat_data = await self._db_run(self.db.get_chat_by_id, formatted_chat_id, self.account_id)
                    if chat_data:
                        chat_data.participants_count = new_count
                        await self._update_chat_cache(chat_data, formatted_chat_id)
                
                logger.info(f"聊天参与者数量更新: chat={formatted_chat_id}, "
                           f"old={current_count}, new={new_count}, change={new_count - current_count}")
                
                # 6. 触发参与者更新事件
                await self._trigger_event('participants_update', {
                    'type': 'participants_updated',
                    'chat_id': formatted_chat_id,
                    'chat_type': chat_type,
                    'old_count': current_count,
                    'new_count': new_count,
                    'change': new_count - current_count,
                    'user_id': user.id if user else None,
                    'user_name': self._get_user_display_name(user) if user else None,
                    'timestamp': datetime.now(),
                    'account_id': self.account_id
                })
                
        except Exception as e:
            logger.error(f"更新聊天参与者数量失败 {chat.id}: {e}", exc_info=True)
            

            
    async def _update_chat_cache(self, chat_data: ChatModel, chat_id: str):
        """更新聊天缓存"""
        try:
            # 更新哈希缓存
            await self.cache.set_hash(
                "chats",
                chat_id,
                chat_data.to_dict(),
                Config.CACHE_TTL['chats']
            )
            
            # 更新列表缓存
            await self._update_chat_in_list_cache(chat_id, chat_data)
            
        except Exception as e:
            logger.error(f"更新聊天缓存失败 {chat_id}: {e}")
    
    async def _update_chat_in_list_cache(self, chat_id: str, chat_data: ChatModel):
        """更新聊天列表缓存中的单个聊天"""
        try:
            chat_list = await self.cache.get_list(
                "chat_list",
                account_id=self.account_id
            )
            
            if not chat_list:
                return
            
            # 查找并更新聊天
            updated = False
            for i, chat in enumerate(chat_list):
                if chat.get('chat_id') == chat_id:
                    chat_list[i] = chat_data.to_dict()
                    updated = True
                    break
            
            # 如果没找到，添加到列表开头
            if not updated:
                chat_list.insert(0, chat_data.to_dict())
            
            # 保持列表长度
            if len(chat_list) > 100:
                chat_list = chat_list[:100]
            
            await self.cache.set_list(
                "chat_list",
                chat_list,
                Config.CACHE_TTL['chats'],
                account_id=self.account_id
            )
            
            logger.debug(f"[{self.account_id}] 更新聊天列表缓存: chat_id={chat_id}")
                
        except Exception as e:
            logger.error(f"[{self.account_id}] 更新聊天列表缓存失败 {chat_id}: {e}")
    
    
    def _check_client_status(self) -> bool:
        """检查客户端状态"""
        try:
            if not hasattr(self, 'client') or not self.client:
                logger.warning("客户端不存在")
                return False            
            if hasattr(self.client, "is_connected"):
                return bool(self.client.is_connected())
            return True
            
        except Exception as e:
            logger.error(f"检查客户端状态异常: {e}")
            return False

 
 
    
     
    async def _safe_get_dialogs(self, limit: int = 100) -> List[Dialog]:
        """安全获取对话框"""
        async with self._dlg_lock:
            for attempt in range(3):
                try:
                    await self._wait_for_api_slot("get_dialogs", min_interval=0.8)
                    if self._io_lock:
                        async with self._conn_guard():
                            async with self._io_lock:
                                return await self.client.get_dialogs(
                                    limit=limit,
                                    ignore_migrated=True
                                )
                    else:
                        async with self._conn_guard():
                            return await self.client.get_dialogs(
                                limit=limit,
                                ignore_migrated=True
                            )
                except FloodWaitError as e:
                    logger.warning(f"[{self.account_id}] iter_dialogs FloodWait {e.seconds}s")
                    await asyncio.sleep(e.seconds)
                except Exception as e:
                    logger.warning(f"[{self.account_id}] iter_dialogs失败({attempt+1}/3): {e}")
                    await asyncio.sleep(1.5 * (attempt + 1))
        
        logger.error(f"[{self.account_id}] iter_dialogs 失败超过 3 次")
        return []
    
    async def _invalidate_dialogs_cache(self):
        """使对话框缓存失效"""
        self._cached_dialogs = None
        self._cached_dialogs_time = None
        logger.debug("对话框缓存已失效")
    
    # ============================================
    # 统一同步方法
    # ============================================
    
    
    
    async def _process_chat_info(self, dialog: Dialog) -> bool:
        """处理聊天信息（返回是否成功处理）"""
        try:
            # 提取聊天信息
            chat_info = await self._extract_chat_info(dialog)
            if not chat_info:
                logger.warning(f"无法提取聊天信息，跳过: {dialog.id}")
                return False
            
            # 检查聊天是否已存在
            existing_chat = await self._get_cached_chat(chat_info['chat_id'])
            if existing_chat and self._is_chat_unchanged(existing_chat, chat_info):
                logger.debug(f"聊天数据未变化，跳过保存: {dialog.id}")
                return True
            
            # 下载头像
            avatar_path = None
            if dialog.is_user:
                avatar_path = await self._download_user_avatar(dialog.entity)
            else:
                avatar_path = await self._download_group_avatar(dialog.entity)
            
            # 获取参与者数量（如果是群组或频道）
            #participants_count = await self._get_actual_participants_count_for_dialog(dialog)
            
            # 创建聊天模型
            chat_model = ChatModel(
                chat_id=chat_info['chat_id'],
                account_id=self.account_id,
                chat_type=chat_info['chat_type'],
                title=chat_info['title'],
                username=chat_info['username'],
                unread_count=chat_info['unread_count'],
                last_message_id=dialog.message.id if dialog.message else None,
                last_message_time=chat_info['last_message_time'],
                avatar_path=avatar_path,
                participants_count=0,
            )
            
            # 保存到数据库
            
            await self._db_run(self.db.save_chat,chat_model)
            
            # 更新缓存
            await self._update_chat_cache(chat_model, chat_info['chat_id'])
            
            logger.debug(f"聊天信息已更新: {dialog.id} ({chat_info['chat_type']}), ")
            return True
            
        except Exception as e:
            logger.error(f"处理聊天信息失败 {dialog.id}: {e}")
            return False
    
    def _is_chat_unchanged(self, old_chat: ChatModel, new_chat_info: Dict) -> bool:
        """检查聊天数据是否发生变化"""
        #old_participants = old_chat.participants_count or 0
        #new_participants = new_chat_info.get('participants_count', 0) or 0
        
        return (
            old_chat.title == new_chat_info['title'] and
            old_chat.chat_type == new_chat_info['chat_type'] and
            old_chat.username == new_chat_info['username'] 
            #old_participants == new_participants
        )
    
    # ============================================
    # 消息处理方法
    # ============================================
    
    async def _handle_new_message(self, event):
        """处理新消息事件"""
        try:
            logger.info(f"监听器新消息处理")
            if not self.is_monitoring:
                logger.info("监听器已停止，跳过新消息处理")
                return
            
            if not self._check_client_status():
                logger.info("客户端未连接，跳过新消息处理")
                return
            
            message = event.message
            logger.info(f"监听器新消息{message}")
            # 调试日志：记录所有私聊消息
            if hasattr(message, 'is_private') and message.is_private:
                logger.debug(f"[{self.account_id}] 收到私聊消息: msg_id={message.id}, sender={message.sender_id}, out={message.out}")
            
           
            # 分析消息聊天信息
            chat_info = await self._message_chat_info(event)
            if not chat_info:
                logger.error(f"无法分析聊天信息: msg_id={message.id}")
                return
            
            logger.info(f"新消息处理完成: chat_info={chat_info}")
            newchat_id = chat_info['newchat_id']
            
            # 检查消息是否已存在
            '''
            existing_msg = await self._get_cached_message(message.id, newchat_id)
            if existing_msg:
                logger.debug(f"新消息已存在，跳过: msg_id={message.id}, chat_id={newchat_id}")
                return
            '''
            # 获取或创建聊天记录
            existing_chat = await self._get_cached_chat(newchat_id)
            if not existing_chat:
                await self._create_chat_record(message, chat_info)
            
            # 处理消息
            await self._process_single_message(message, chat_info,event)
            
            # 更新聊天最后消息
            '''
            await self._db_run(
                self.db.update_chat_last_message,
                chat_id=newchat_id,
                account_id=self.account_id,
                last_message_id=message.id,
                last_message_time=message.date
            )
            '''
            # 更新聊天未读数
            if not message.out: 
                if chat_info['chat_type'] == 'private':
                    await self._db_run(self.db.increment_chat_unread, newchat_id, self.account_id)
                else:
                    await self.update_chat_unread(newchat_id, self.account_id)
                
            
            # 更新缓存
            '''
            chat_data = await self._db_run(self.db.get_chat_by_id, newchat_id, self.account_id)
            if chat_data:
                await self._update_chat_cache(chat_data, newchat_id)
            '''
            await self._invalidate_dialogs_cache_throttled()
            
            # 触发事件
            await self._trigger_event('new_message', {
                'type': 'new_message',
                'message_data': {
                    'id': message.id,
                    'chat_id': newchat_id,
                    'chat_type': chat_info['chat_type'],
                    'text': message.text,
                    'sender_id': await self._get_message_sender_id(message),
                    'sender_name': await self._get_sender_name(message),
                    'timestamp': message.date,
                    'is_outgoing': message.out,
                    'is_bot': False,
                    'message': message
                },
                'account_id': self.account_id
            })
            
            logger.info(f"新消息处理完成: msg={message.id}, chat={newchat_id}, type={chat_info['chat_type']}")
                    
        except Exception as e:
            logger.error(f"处理新消息事件失败: {e}", exc_info=True)
    
    async def _create_chat_record(self, message, chat_info: Dict):
        """创建聊天记录"""
        try:
            entity = None
            try:
                entity = chat_info['chat_entity']
            except Exception as e:
                logger.warning(f"创建聊天记录时获取实体失败，尝试使用基础信息构建: {e}")
            
            # 构建聊天信息
            # 如果成功获取了 entity
            if entity:
                if chat_info['chat_type'] == 'private' and isinstance(entity, User):
                    title = chat_info['name']
                    username = chat_info['username']
                    is_bot = getattr(entity, 'bot', False)
                    participants_count = 1
                else:
                    title = chat_info['name']
                    username = chat_info['username']
                    is_bot = False
                    #participants_count = await self._get_actual_participants_count(entity)
            else:
                # 降级处理：如果没有获取到 entity，使用 chat_info 中的基本信息
                title = f"User_{chat_info['newchat_id']}" if chat_info['chat_type'] == 'private' else f"Chat_{chat_info['newchat_id']}"
                username = None
                is_bot = False # 默认假定不是
                #participants_count = 1 if chat_info['chat_type'] == 'private' else 0
            
            # 获取头像
            avatar_path = None
            if entity:
                if chat_info['chat_type'] == 'private' and hasattr(entity, 'photo'):
                    avatar_path = await self._download_user_avatar(entity)
                elif hasattr(entity, 'photo'):
                    avatar_path = await self._download_group_avatar(entity)
            
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
                participants_count=1,
                is_bot=is_bot
            )
            
            # 保存到数据库
           
            await self._db_run(self.db.save_chat, chat_model)
            
            logger.info(f"创建聊天室成功: {chat_info['newchat_id']} ({chat_info['chat_type']})")
            
        except Exception as e:
            logger.error(f"创建聊天室失败: {e}")
            
    async def update_chat_unread(self, chat_id: str, account_id: str):
        """消息到来时，先更新内存计数"""
        key = (account_id, chat_id)
        self._unread_cache[key] += 1
    
        # 随机或定时触发落库
        if self._unread_cache[key] >= 99:  # 累积10条消息更新一次
            await self._flush_unread(chat_id, account_id)
    
    async def _flush_unread(self, chat_id: str, account_id: str):
        """批量写回数据库"""
        key = (account_id, chat_id)
        count = self._unread_cache.get(key, 0)
        if count == 0:
            return
    
        try:
            await self._db_run(self.db.increment_chats_unread, chat_id, self.account_id,count)
            logger.debug(f"聊天未读数批量加 {count}: chat_id={chat_id}")
        except Exception as e:
            logger.error(f"聊天未读数批量加失败 {chat_id}: {str(e)}")
        finally:
            # 清空缓存计数
            self._unread_cache[key] = 0        
    
    async def _process_messages_for_dialog(self, dialog: Dialog, formatted_chat_id: str, is_full_sync: bool, unread_count: int, last_stored_id: int = 0) -> Dict[str, int]:
        """为对话框处理消息"""
        try:
            stats = {'processed': 0, 'skipped_bot': 0}
            
            entity = dialog.entity or await self._get_entity_cached(dialog.id)

            if last_stored_id > 0:
                limit = 300 if is_full_sync else max(100, int(unread_count or 0))
                limit = min(limit, 500)
                iter_kwargs = {"min_id": int(last_stored_id), "limit": limit, "reverse": True}
            else:
                limit = 50 if is_full_sync else max(50, int(unread_count or 0))
                limit = min(limit, 200)
                iter_kwargs = {"limit": limit, "reverse": True}

            newest_id = 0
            newest_time = None
            
            async with self._conn_guard():
                async for msg in self.client.iter_messages(entity, **iter_kwargs):
                    if await self._is_bot_message(msg):
                        stats['skipped_bot'] += 1
                        continue
                    
                    existing_msg = await self._get_cached_message(msg.id, formatted_chat_id)
                    if existing_msg:
                        continue
                    
                    await self._process_single_message(msg, formatted_chat_id)
                    stats['processed'] += 1
                    if int(msg.id) > newest_id:
                        newest_id = int(msg.id)
                        newest_time = getattr(msg, "date", None)

            dialog_last_id = int(dialog.message.id) if getattr(dialog, "message", None) and getattr(dialog.message, "id", None) else 0
            dialog_last_time = getattr(dialog.message, "date", None) if getattr(dialog, "message", None) else None

            target_last_id = max(int(last_stored_id or 0), dialog_last_id, newest_id)
            target_last_time = dialog_last_time or newest_time
            if target_last_id > int(last_stored_id or 0) and target_last_time:
                await self._db_run(
                    self.db.update_chat_last_message,
                    chat_id=formatted_chat_id,
                    account_id=self.account_id,
                    last_message_id=target_last_id,
                    last_message_time=target_last_time
                )
            
            return stats
                
        except Exception as e:
            logger.error(f"处理对话框消息失败 {dialog.id}: {e}")
            return {'processed': 0, 'skipped_bot': 0}
    #新消息处理完成: {'peer': <telethon.tl.types.PeerUser object at 0x7f78fd3eced0>, 'chat_type': 'private', 'oldchat_id': 8412902216, 'newchat_id': '8412902216', 'name': 'Jody Bures', 'input_peer': None, 'is_outgoing': False}
    async def _process_single_message(self, msg, chat_info,event):
        """处理单条消息"""
        try:
            chat_id=chat_info['newchat_id']
            sender_id= None
            sender_name = None
            sender_username = None
            media_path = None
            
            sender_id = event.sender_id
            sender = event.sender
            if not sender:
                sender = await self.get_sender_cached(event)
            
            sender_username = getattr(sender, "username", None) if sender else None
            sender_name = self._format_user_name(sender) if sender else None
            '''
            # 安全获取sender_id
            sender_id = await self._get_safe_sender_id(msg, chat_id)
            if sender_id is None:
                sender_id = 0
            
            # 下载媒体文件
            media_path = None
            if msg.media:
                media_path = await self._download_media(msg, chat_id)
            
            sender_name = None
            sender_username = None
            if sender_id and isinstance(sender_id, int) and sender_id > 0:
                sender_info = await self._get_user_info_cached(sender_id)
                if sender_info:
                    sender_name = sender_info.get('name')
                    sender_username = sender_info.get('username')
            if not sender_name:
                sender_name = await self._get_sender_name(msg)
            if not sender_username:
                sender_username = await self._get_sender_username(msg)
            '''    
            message_type = self._get_message_type(msg)
            
            # 创建消息模型
            message_model = MessageModel(
                message_id=msg.id,
                chat_id=chat_id,
                account_id=self.account_id,
                sender_id=sender_id,
                sender_name=sender_name,
                sender_username=sender_username,
                message_text=msg.text or "",
                message_type=message_type,
                media_path=media_path,
                is_outgoing=msg.out,
                reply_to_msg_id=msg.reply_to.reply_to_msg_id if msg.reply_to else None,
                timestamp=msg.date,
                is_read=msg.out or (hasattr(msg, 'unread') and not msg.unread)
            )
            chat_type = chat_info['chat_type']
            newchat_id=chat_info['newchat_id']
            if chat_type == "private":
                # 保存到数据库
                saved = await self._db_run(self.db.save_message, message_model)
                if not saved:
                    logger.warning(f"[{self.account_id}] 消息写入数据库失败: chat={chat_id}, msg={msg.id}")
                    return
                
                # 添加到缓存
                await self.cache.add_to_list(
                    "messages",
                    message_model.to_dict(),
                    100,
                    Config.CACHE_TTL['messages'],
                    chat_id
                )
            # 群聊 -> 缓存
            else:
                cache_groupkey = f"group_message_{newchat_id}"
            
                await self.cache.add_to_list(
                    cache_groupkey,
                    message_model.to_dict(),
                    200,  # 最多保存100条
                    Config.CACHE_TTL['group']
                )
            logger.info(f"保存消息: chat={chat_id}, msg={msg.id}, sender={sender_name}")
                
        except Exception as e:
            logger.error(f"处理消息失败 {msg.id}: {e}")
    
    
    async def get_sender_cached(self, event):
        sender_id = event.sender_id
    
        if sender_id in self.sender_cache:
            return self.sender_cache[sender_id]
    
        sender = await event.get_sender()
    
        if sender:
            self.sender_cache[sender_id] = sender
    
        return sender
    
            
    async def _get_safe_sender_id(self, msg, chat_id) -> int:
        """安全获取发送者ID"""
        try:
            # 方法1: 直接从消息获取
            if hasattr(msg, 'sender_id') and msg.sender_id:
                if isinstance(msg.sender_id, int):
                    return msg.sender_id
                elif hasattr(msg.sender_id, 'user_id'):
                    return msg.sender_id.user_id
                elif hasattr(msg.sender_id, 'channel_id'):
                    return msg.sender_id.channel_id
            
            # 方法2: 从from_id获取
            if hasattr(msg, 'from_id') and msg.from_id:
                if isinstance(msg.from_id, int):
                    return msg.from_id
                elif hasattr(msg.from_id, 'user_id'):
                    return msg.from_id.user_id
                elif hasattr(msg.from_id, 'channel_id'):
                    return msg.from_id.channel_id
            
            # 方法3: 从sender实体获取
            if hasattr(msg, 'sender') and msg.sender:
                if hasattr(msg.sender, 'id'):
                    return msg.sender.id
            
            # 方法4: 从peer_id获取（对于频道消息）
            if hasattr(msg, 'peer_id') and msg.peer_id:
                if hasattr(msg.peer_id, 'channel_id'):
                    return msg.peer_id.channel_id
                elif hasattr(msg.peer_id, 'user_id'):
                    return msg.peer_id.user_id
            
            # 方法5: 通过API获取实体
            if hasattr(msg, 'chat_id'):
                try:
                    # 对于群组消息，可能是群组本身发送的
                    if hasattr(msg, 'chat'):
                        return msg.chat.id
                except:
                    pass
            
            # 方法6: 私聊兜底
            # 如果是私聊，且是接收到的消息，sender_id 应该是 chat_id (对方的ID)
            # chat_id 参数是 newchat_id (str)，对于私聊通常是 "12345"
            try:
                if chat_id and not str(chat_id).startswith('-'):
                    # 再次确认消息是否真的是私聊
                    if hasattr(msg, 'is_private') and msg.is_private:
                        if not msg.out:
                            # 接收到的私聊消息，发送者就是对方
                            return int(chat_id)
                        else:
                            # 发送出的私聊消息，发送者是自己
                            me = await self._get_me_cached()
                            if me:
                                return me.id
            except Exception:
                pass

            logger.debug(f"[{self.account_id}] 无法获取sender_id: msg={msg.id}, chat={chat_id}")
            return None
        except Exception as e:
            logger.error(f"[{self.account_id}] 获取sender_id失败: {e}")
       
        
    async def _is_bot_message(self, msg) -> bool:
        """检查消息是否来自机器人"""
        try:
            if not msg.sender:
                return False
            
            # 如果发送者是机器人
            if hasattr(msg.sender, 'bot') and msg.sender.bot:
                return True
            
            # 如果消息来自群组，检查发送者是否为机器人
            if msg.sender_id:
                try:
                    info = await self._get_user_info_cached(msg.sender_id)
                    if info:
                        return info.get('is_bot', False)
                except Exception:
                    pass
            
            return False
        except Exception as e:
            logger.debug(f"检查机器人消息失败: {e}")
            return False
    
    
            
    async def _message_chat_info(self, event) -> Optional[Dict]:
        """获取对话信息"""
        try:
            message = event.message
            peer = message.peer_id
            
            # 生成缓存key
            if hasattr(peer, 'user_id'):
                cache_key = f"chat_info:private:{peer.user_id}"
            elif hasattr(peer, 'chat_id'):
                cache_key = f"chat_info:group:{peer.chat_id}"
            elif hasattr(peer, 'channel_id'):
                cache_key = f"chat_info:channel:{peer.channel_id}"
            else:
                cache_key = None
            
            # 尝试从缓存获取
            if cache_key and hasattr(self, '_chat_info_cache'):
                cached = self._chat_info_cache.get(cache_key)
                if cached and time.time() - cached['time'] < 600:  # 10分钟缓存
                    logger.debug(f"使用缓存的聊天信息: {cache_key}")
                    # 更新动态字段
                    cached['info']['is_outgoing'] = getattr(message, 'out', False)
                    return cached['info']
                    
             # 基础信息
            chat_info = {
                'peer': peer,
                'chat_type': None,
                'oldchat_id': None,
                'newchat_id': None,
                'name': None,
                'username': None,
                'input_peer': None,  # 添加 input_peer 供后续使用
                'chat_entity':None
            }
            # 获取 input_peer（最可靠的实体引用）
            try:
                chat_info['input_peer'] = await self.client.get_input_entity(peer)
            except:
                pass
            
            if hasattr(peer, 'user_id'):
                # 私聊
                chat_info.update({
                    'chat_type': 'private',
                    'oldchat_id': peer.user_id,
                    'newchat_id': str(peer.user_id),
                })
                
                # 获取用户名称
                try:
                    sender = event.sender
                    
                    if not sender:
                        sender = await self.get_sender_cached(event)
                    
                    chat_info['username'] = getattr(sender, "username", None) if sender else None
                    chat_info['name'] = self._format_user_name(sender) if sender else None
                  
                    logger.info(f"event：name: {sender}")
                    chat_info['chat_entity']=sender
                except:
                    pass
                    
            elif hasattr(peer, 'chat_id'):
                # 普通群组
                chat_info.update({
                    'chat_type': 'group',
                    'oldchat_id': peer.chat_id,
                    'newchat_id': f"-{peer.chat_id}",
                })
                
                # 获取群组名称
                try:
                    chat_entity = await self.client.get_entity(peer)
                    chat_info['name'] = getattr(chat_entity, 'title', None)
                    chat_info['username'] = getattr(chat_entity, 'username', None)
                    chat_info['chat_entity']=chat_entity
                except Exception as e:
                    logger.debug(f"获取群组名称失败: {e}")
                    
            elif hasattr(peer, 'channel_id'):
                # 频道或超级群组
                channel_id = peer.channel_id
                positive_id = abs(channel_id)
                
                chat_info.update({
                    'oldchat_id': channel_id,
                    'newchat_id': f"-100{positive_id}", 
                })
                
                # 判断具体类型
                try:
                    chat_entity = await self.client.get_entity(peer)
                    chat_info['name'] = getattr(chat_entity, 'title', None)
                    chat_info['username'] = getattr(chat_entity, 'username', None) 
                    chat_info['chat_entity']=chat_entity
                    if hasattr(chat_entity, 'megagroup') and chat_entity.megagroup:
                        chat_info['chat_type'] = 'supergroup'
                    elif hasattr(chat_entity, 'broadcast') and chat_entity.broadcast:
                        chat_info['chat_type'] = 'channel'
                    else:
                        chat_info['chat_type'] = 'channel'  # 默认
                except:
                    chat_info['chat_type'] = 'channel'  # 无法获取时的默认值
            chat_info['is_outgoing'] =  getattr(message, 'out', False)
            
            # 存入缓存
            if cache_key:
                if not hasattr(self, '_chat_info_cache'):
                    self._chat_info_cache = {}
                
                # 清理过期缓存
                self._clean_chat_info_cache()
                
                self._chat_info_cache[cache_key] = {
                    'info': chat_info.copy(),
                    'time': time.time()
                }
            return chat_info
            
        except Exception as e:
            logger.error(f"获取聊天信息失败: {e}")
            return None
    def _clean_chat_info_cache(self, max_size: int = 1000):
        """清理过期的聊天信息缓存"""
        if not hasattr(self, '_chat_info_cache'):
            return
        
        now = time.time()
        # 清理过期缓存（超过15分钟）
        expired_keys = [
            k for k, v in self._chat_info_cache.items() 
            if now - v['time'] > 900  # 15分钟
        ]
        for k in expired_keys:
            del self._chat_info_cache[k]
        
        # 如果缓存太大，清理最旧的
        if len(self._chat_info_cache) > max_size:
            sorted_items = sorted(
                self._chat_info_cache.items(), 
                key=lambda x: x[1]['time']
            )
            for k, _ in sorted_items[:len(sorted_items) - max_size]:
                del self._chat_info_cache[k]
    def _format_user_name(self, user) -> str:
        """格式化用户显示名称"""
        if not user:
            return None
        
        first = getattr(user, 'first_name', '')
        last = getattr(user, 'last_name', '')
        
        if first and last:
            return f"{first} {last}"
        elif first:
            return first
        elif last:
            return last
        else:
            return getattr(user, 'username', None) or str(getattr(user, 'id', ''))
    
            
    async def _analyze_chat_info(self, chat) -> Optional[Dict]:
        """分析聊天信息"""
        try:
            if isinstance(chat, User):
                return {
                    'newchat_id': f"{chat.id}",
                    'oldchat_id': chat.id,
                    'chat_type': 'private'
                }
            elif isinstance(chat, Chat):
                return {
                    'newchat_id': f"-{chat.id}",
                    'oldchat_id': chat.id,
                    'chat_type': 'group'
                }
            elif isinstance(chat, Channel):
                # 标准化聊天ID
                if chat.id < 0:
                    positive_id = abs(chat.id)
                else:
                    positive_id = chat.id
                
                chat_type = 'supergroup' if chat.megagroup else 'channel'
                
                return {
                    'newchat_id': f"-100{positive_id}",
                    'oldchat_id': chat.id,
                    'chat_type': chat_type
                }
            
            return None
            
        except Exception as e:
            logger.error(f"分析聊天信息失败 {chat.id}: {e}")
            return None
    
    async def _create_chat_from_entity(self, entity, formatted_chat_id: str, chat_type: str):
        """从实体创建聊天记录"""
        try:
            # 获取参与者数量
            #participants_count = await self._get_actual_participants_count(entity)
            
            # 获取标题
            if chat_type == 'private' and isinstance(entity, User):
                title = self._get_user_display_name(entity)
                username = entity.username
                is_bot = entity.bot
            else:
                title = getattr(entity, 'title', f"{chat_type}_{entity.id}")
                username = getattr(entity, 'username', None)
                is_bot = False
            
            # 下载头像
            avatar_path = None
            if chat_type == 'private' and hasattr(entity, 'photo'):
                avatar_path = await self._download_user_avatar(entity)
            elif hasattr(entity, 'photo'):
                avatar_path = await self._download_group_avatar(entity)
            
            # 创建聊天模型
            chat_model = ChatModel(
                chat_id=formatted_chat_id,
                account_id=self.account_id,
                chat_type=chat_type,
                title=title,
                username=username,
                unread_count=0,
                last_message_id=None,
                last_message_time=datetime.now(),
                avatar_path=avatar_path,
                #participants_count=participants_count,
                is_bot=is_bot
            )
            
            # 保存到数据库
            await self._db_run(self.db.save_chat,chat_model)
            
            # 更新缓存
            await self._update_chat_cache(chat_model, formatted_chat_id)
            
            logger.info(f"创建聊天记录成功: {formatted_chat_id} ({chat_type}), ")
            
        except Exception as e:
            logger.error(f"创建聊天记录失败 {formatted_chat_id}: {e}")
    
    # ============================================
    # 参与者数量获取方法
    # ============================================
    
    async def _get_actual_participants_count_for_dialog(self, dialog: Dialog) -> int:
        """为对话框获取实际的参与者数量"""
        try:
            entity = dialog.entity
            
            # 私聊固定为1
            if dialog.is_user:
                return 1
            
            # 尝试从实体属性获取
            if hasattr(entity, 'participants_count'):
                count = getattr(entity, 'participants_count', 0)
                if count and count > 0:
                    return count
            
            # 尝试从缓存获取
            try:
                chat_info = await self._analyze_chat_info(entity)
                if chat_info:
                    cached_count = await self._get_cached_participants_count(chat_info['newchat_id'])
                    if cached_count is not None:
                        return cached_count
            except Exception:
                pass

            # 尝试通过API获取
            try:
                async with self._conn_guard():
                    await self._wait_for_api_slot("get_participants", min_interval=0.7)
                    participants = await self.client.get_participants(entity, limit=1)
                if participants and hasattr(participants, 'total'):
                    # 缓存结果
                    if chat_info:
                         await self._set_cached_participants_count(chat_info['newchat_id'], participants.total)
                    return participants.total
            except Exception as e:
                logger.debug(f"通过API获取参与者总数失败 {dialog.id}: {e}")
            
            # 根据聊天类型返回默认值
            if dialog.is_group:
                return 10  # 普通群组默认值
            elif dialog.is_channel:
                # 判断是频道还是超级群组
                if hasattr(entity, 'megagroup') and entity.megagroup:
                    return 50  # 超级群组默认值
                else:
                    return 100  # 频道默认值
            
            return 0
                
        except Exception as e:
            logger.error(f"获取对话框参与者数量失败 {dialog.id}: {e}")
            return 0
    async def _get_cached_participants_count(self, chat_id: str) -> Optional[int]:
        """从缓存获取参与者数量"""
        try:
            cache_key = f"participants:{self.account_id}:{chat_id}"
            cached = await self.cache.get(cache_key)
            if cached is not None:
                return int(cached)
            return None
        except Exception as e:
            logger.debug(f"获取缓存参与者数量失败 {chat_id}: {e}")
            return None
    
    async def _set_cached_participants_count(self, chat_id: str, count: int, ttl: int = 3600):
        """缓存参与者数量"""
        try:
            cache_key = f"participants:{self.account_id}:{chat_id}"
            await self.cache.set(cache_key, count, ttl)
        except Exception as e:
            logger.debug(f"缓存参与者数量失败 {chat_id}: {e}")
            
    async def _get_actual_participants_count(self, entity, use_cache=True) -> int:
        """获取实际的参与者数量"""
        try:
            # 私聊固定为1
            if isinstance(entity, User):
                return 1
            chat_info = await self._analyze_chat_info(entity)
            if not chat_info:
                return 0
            
            chat_id = chat_info['newchat_id']
            
            # 检查缓存
            if use_cache:
                cached_count = await self._get_cached_participants_count(chat_id)
                if cached_count is not None:
                    return cached_count
            # 尝试从实体属性获取
            if hasattr(entity, 'participants_count'):
                count = getattr(entity, 'participants_count', 0)
                if count and count > 0:
                    await self._set_cached_participants_count(chat_id, count)
                    return count
            
            # 尝试通过API获取
            try:
                async with self._conn_guard():
                    await self._wait_for_api_slot("get_participants", min_interval=0.7)
                    participants = await self.client.get_participants(entity, limit=1)
                if hasattr(participants, 'total') and participants.total:
                    count = participants.total
                    await self._set_cached_participants_count(chat_id, count)
                    return count
            except Exception as e:
                logger.debug(f"通过API获取参与者总数失败 {entity.id}: {e}")
            
            # 根据聊天类型返回默认值
            if isinstance(entity, Chat):
                return 10  # 普通群组默认值
            elif isinstance(entity, Channel):
                if entity.megagroup:
                    return 50  # 超级群组默认值
                else:
                    return 100  # 频道默认值
            
            return 0
                
        except Exception as e:
            logger.error(f"获取实际参与者数量失败 {entity.id}: {e}")
            return 0
    
    # ============================================
    # 缓存相关方法
    # ============================================
    
    async def _get_cached_chat(self, chat_id: str):
        """从缓存获取聊天数据"""
        try:
            # 先尝试从缓存获取
            chat_dict = await self.cache.get_hash("chats", chat_id)
            if chat_dict:
                logger.info(f"命中从缓存获取聊天数据")
                return ChatModel.from_dict(chat_dict)
            
            # 缓存未命中，查询数据库
            chat_data = await self._db_run(self.db.get_chat_by_id,chat_id, self.account_id)
            if chat_data:
                logger.info(f"数据库获取聊天数据")
                # 更新缓存
                await self.cache.set_hash(
                    "chats",
                    chat_id,
                    chat_data.to_dict(),
                    Config.CACHE_TTL['chats']
                )
            
            return chat_data
        except Exception as e:
            logger.error(f"获取聊天数据失败 {chat_id}: {e}")
            return None
    
    async def _get_cached_message(self, message_id: int, chat_id: str):
        """从缓存获取消息数据"""
        try:
            # 检查缓存列表
            messages_list = await self.cache.get_list("messages", 0, -1, None, chat_id)
            if messages_list:
                for msg_dict in messages_list:
                    if msg_dict.get('message_id') == message_id:
                        logger.debug(f"消息缓存命中: chat={chat_id}, msg={message_id}")
                        return MessageModel.from_dict(msg_dict)
            # 2. 如果是群组，不查询数据库，直接返回 None
            if chat_id.startswith("-") or str(chat_id).startswith("-100"):
                logger.debug(f"群组消息不查询数据库: chat={chat_id}, msg={message_id}")
                return None
            # 查询数据库
            logger.debug(f"消息缓存未命中，查询数据库: chat={chat_id}, msg={message_id}")
            message = await self._db_run(self.db.get_message,message_id, chat_id, self.account_id)
            
            # 添加到缓存
            if message:
                await self.cache.add_to_list(
                    "messages",
                    message.to_dict(),
                    100,
                    Config.CACHE_TTL['messages'],
                    chat_id
                )
            
            return message
            
        except Exception as e:
            logger.error(f"获取消息数据失败 {message_id}: {e}")
            return None
    
    async def _refresh_chat_cache_unread(self, chat_ids: List[str]):
        """刷新聊天缓存中的未读数"""
        try:
            for chat_id in chat_ids:
                chat_data = await self._db_run(self.db.get_chat_by_id,chat_id, self.account_id)
                if chat_data:
                    await self.cache.set_hash(
                        "chats",
                        chat_id,
                        chat_data.to_dict(),
                        Config.CACHE_TTL['chats']
                    )
            
            await self._refresh_chat_list_cache()
            
        except Exception as e:
            logger.error(f"[{self.account_id}] 刷新聊天缓存未读数失败: {e}")
    
    async def _refresh_chat_list_cache(self):
        """刷新聊天列表缓存"""
        try:
            chats =  await self._db_run(self.db.get_chats,
                account_id=self.account_id, 
                limit=100, 
                offset=0
            )
            
            if chats:
                chat_dicts = [chat.to_dict() for chat in chats]
                
                await self.cache.set_list(
                    "chat_list",
                    chat_dicts,
                    Config.CACHE_TTL['chats'],
                    account_id=self.account_id
                )
                
                logger.debug(f"[{self.account_id}] 刷新聊天列表缓存: {len(chat_dicts)} 个聊天")
                
                # 触发事件
                await self._trigger_event('chat_update', {
                    'type': 'chat_list_updated',
                    'chat_id': self.account_id,
                    'chats': chat_dicts,
                    'timestamp': datetime.now()
                })
                    
        except Exception as e:
            logger.error(f"[{self.account_id}] 刷新聊天列表缓存失败: {e}")
    
    # ============================================
    # 联系人相关方法
    # ============================================
    
    async def _get_friend_count(self) -> int:
        """统计好友数量"""
        key_friends = f"friend_count:{self.account_id}"
        cached_friends = await self.cache.get(key_friends)
        
        if cached_friends is not None:
            return int(cached_friends)
        
        contacts = await self._safe_get_contacts()
        if contacts and hasattr(contacts, 'users'):
            friend_count = 0
            for user in contacts.users:
                if not user.bot:
                    friend_count += 1
            await self.cache.set(key_friends, friend_count, 3600)
            return friend_count
        else:
            await self.cache.set(key_friends, 0, 300)
            return 0
    
    async def _safe_get_contacts(self):
        """安全获取联系人（带重试机制）"""
        async with self._contact_lock:
            for attempt in range(3):
                try:
                    async with self._conn_guard():
                        await self._wait_for_api_slot("get_contacts", min_interval=0.8)
                        return await self.client(GetContactsRequest(hash=0))
                except FloodWaitError as e:
                    logger.warning(f"[{self.account_id}] GetContacts FloodWait {e.seconds}s")
                    await asyncio.sleep(e.seconds)
                except Exception as e:
                    logger.warning(f"[{self.account_id}] GetContacts失败({attempt+1}/3): {e}")
                    await asyncio.sleep(1.2 * (attempt + 1))
        
        logger.warning(f"[{self.account_id}] GetContacts 失败超过 3 次")
        return None
    
    async def _update_account_stats(self, total_unread: int, group_count: int, friend_count: int):
        """更新账号统计到数据库"""
        try:
            me = await self._get_me_cached(ttl=120)
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
                    await self._db_run(self.db.update_mtuser_unread_by_phone,
                        phone,
                        total_unread,
                        group_count,
                        friend_count
                    )
                    await self.cache.set(key_db, current_state, 600)
                    
                    logger.info(
                        f"[{self.account_id}] 更新统计: 未读={total_unread}, "
                        f"群组={group_count}, 好友={friend_count}"
                    )
                    
        except Exception as e:
            logger.error(f"[{self.account_id}] 更新数据库失败: {e}")
    
    # ============================================
    # 工具方法
    # ============================================
    
    async def _get_user_info_cached(self, user_id: int) -> Optional[Dict]:
        """从缓存获取用户信息，如果不存在则查询API"""
        try:
            current_time = time.time()
            
            # 1. 检查内存缓存
            if user_id in self._user_info_cache:
                info = self._user_info_cache[user_id]
                if current_time - info['time'] < self._user_info_ttl:
                    return info
            
            # 2. 查询API
            cache_key = f"user_info:{user_id}"
            cached_info = await self.cache.get(cache_key)
            if isinstance(cached_info, dict) and cached_info.get('time'):
                if current_time - cached_info['time'] < self._user_info_ttl:
                    self._user_info_cache[user_id] = cached_info
                    return cached_info
            try:
                user = await self._get_entity_cached(user_id, ttl=self._user_info_ttl)
                logger.info(f"[好友={user}]")
                users = await self.client.get_entity(user_id)
                logger.info(f"[好友={users}]")
                if user:
                    # 构建缓存数据
                    first = getattr(user, 'first_name', '') or ''
                    last = getattr(user, 'last_name', '') or ''
                    name = f"{first} {last}".strip()
                    if not name and getattr(user, 'username', None):
                        name = f"@{user.username}"
                    if not name:
                        name = getattr(user, 'title', '') or f"User_{user_id}"
                        
                    info = {
                        'name': name,
                        'username': getattr(user, 'username', None),
                        'is_bot': getattr(user, 'bot', False),
                        'time': current_time
                    }
                    
                    # 更新缓存
                    self._user_info_cache[user_id] = info
                    await self.cache.set(cache_key, info, self._user_info_ttl)
                    
                    # 清理过期缓存 (简单的随机清理，避免每次都遍历)
                    if len(self._user_info_cache) > 1000 and random.random() < 0.05:
                        expired_keys = [k for k, v in self._user_info_cache.items() 
                                      if current_time - v['time'] > self._user_info_ttl]
                        for k in expired_keys:
                            del self._user_info_cache[k]
                            
                    return info
            except ValueError as e:
                if "Could not find the input entity" in str(e):
                    fallback_ttl = max(30, min(120, int(self._user_info_ttl / 5)))
                    info = {
                        'name': f"User_{user_id}",
                        'username': None,
                        'is_bot': False,
                        'time': current_time,
                        'not_found': True
                    }
                    self._user_info_cache[user_id] = info
                    await self.cache.set(cache_key, info, fallback_ttl)
                    logger.debug(f"用户实体未缓存，使用兜底信息 {user_id}: {e}")
                    return info
                logger.debug(f"API获取用户信息失败 {user_id}: {e}")        
            except Exception as e:
                logger.debug(f"API获取用户信息失败 {user_id}: {e}")
                
            return None
            
        except Exception as e:
            logger.error(f"获取用户信息缓存失败: {e}")
            return None

    async def _get_sender_name(self, msg) -> str:
        """获取发送者名称"""
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
            
            # 2. 尝试通过 client 获取
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
            
            if user_id:
                info = await self._get_user_info_cached(user_id)
                if info and info.get('name'):
                    return info['name']
            
            # 3. 返回ID作为名称
            return f"User_{user_id}" if user_id else "Unknown"
            
        except Exception as e:
            logger.error(f"获取发送者名称失败: {e}")
            return "Unknown"
    
    async def _get_sender_username(self, msg) -> Optional[str]:
        """获取发送者用户名"""
        try:
            if msg.sender and hasattr(msg.sender, 'username') and msg.sender.username:
                return msg.sender.username
            
            # 获取用户ID
            user_id = None
            if hasattr(msg, 'from_id') and msg.from_id:
                if isinstance(msg.from_id, PeerUser):
                    user_id = msg.from_id.user_id
            elif hasattr(msg, 'sender_id') and msg.sender_id:
                if isinstance(msg.sender_id, PeerUser):
                    user_id = msg.sender_id.user_id
            
            if user_id:
                info = await self._get_user_info_cached(user_id)
                if info:
                    return info.get('username')
            
            return None
            
        except Exception as e:
            logger.debug(f"获取发送者用户名失败: {e}")
            return None
    
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
            logger.debug(f"获取消息发送者ID失败: {e}")
            return None
    
    def _get_user_display_name(self, user: User) -> str:
        """获取用户显示名称"""
        if not user:
            return "未知用户"
        
        # 检查是否是已删除用户
        if hasattr(user, 'deleted') and user.deleted:
            return f"已删除用户{user.id}"
        
        # 检查是否是机器人
        if hasattr(user, 'bot') and user.bot:
            return f"机器人{user.id}"
        
        # 构建名称
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
    
        
    async def _sync_group_members_in_dialogs(self, dialogs: List[Dialog], sync_stats: Dict[str, Any]):
        """在对话框同步中同步群组成员"""
        try:
            members_synced = 0
            groups_to_sync = []
            
            # 收集需要同步成员的群组
            for dialog in dialogs:
                try:
                    # 只同步群组和超级群组
                    if dialog.is_group or dialog.is_channel:
                        chat_info = await self._analyze_chat_info(dialog.entity)
                        if chat_info and chat_info['chat_type'] in ['group', 'supergroup']:
                            groups_to_sync.append((dialog.entity, chat_info['newchat_id']))
                except Exception as e:
                    logger.warning(f"收集群组同步失败: {e}")
                    continue
            
            # 异步同步每个群组的成员
            if groups_to_sync:
                sync_tasks = []
                for entity, chat_id in groups_to_sync:
                    # 限制并发数，避免同时请求太多
                    if len(sync_tasks) >= 3:  # 最多同时同步3个群组
                        # 等待一批完成
                        results = await asyncio.gather(*sync_tasks, return_exceptions=True)
                        self._process_member_sync_results(results)
                        sync_tasks = []
                    
                    task = asyncio.create_task(self._sync_group_members_with_retry(entity, chat_id))
                    sync_tasks.append(task)
                
                # 等待剩余的同步任务完成
                if sync_tasks:
                    results = await asyncio.gather(*sync_tasks, return_exceptions=True)
                    members_synced = self._process_member_sync_results(results)
            
            sync_stats['members_synced'] = members_synced
            sync_stats['groups_synced'] = len(groups_to_sync)
            
        except Exception as e:
            logger.error(f"同步群组成员失败: {e}")
            sync_stats['members_synced'] = 0
            sync_stats['groups_synced'] = 0
    
    def _process_member_sync_results(self, results) -> int:
        """处理成员同步结果"""
        total_members = 0
        for result in results:
            if isinstance(result, Exception):
                logger.error(f"成员同步任务失败: {result}")
            elif isinstance(result, int):
                total_members += result
        return total_members
    
    async def _sync_group_members_with_retry(self, entity, chat_id: str, max_retries: int = 2) -> int:
        """带重试机制的群组成员同步"""
        for attempt in range(max_retries):
            try:
                return await self._sync_group_members(entity, chat_id)
            except FloodWaitError as e:
                wait_time = e.seconds
                logger.warning(f"[{self.account_id}] 成员同步 FloodWait {wait_time}s, chat={chat_id}")
                await asyncio.sleep(wait_time)
            except Exception as e:
                if attempt < max_retries - 1:
                    wait_time = 2 * (attempt + 1)
                    logger.warning(f"[{self.account_id}] 成员同步失败({attempt+1}/{max_retries}): {e}, chat={chat_id}")
                    await asyncio.sleep(wait_time)
                else:
                    logger.error(f"[{self.account_id}] 成员同步最终失败: {e}, chat={chat_id}")
                    raise
        return 0    
    async def _process_group_member(self, user, chat_id: str, role: str = 'member',joined_at: Optional[datetime] = None):
        """处理群组成员信息"""
        try:
            if not user:
                return None
            
            # 跳过机器人
            if hasattr(user, 'bot') and user.bot:
                logger.debug(f"跳过机器人成员: user={user.id}")
                return None
            
            # 检查成员是否已存在
            existing_member = await self._db_run(self.db.get_group_member,user.id, chat_id)
            
            # 准备成员数据
            if joined_at is None:
                joined_at = datetime.now()
            
            member_data = GroupMemberModel(
                member_id=user.id,
                chat_id=chat_id,
                username=user.username,
                first_name=user.first_name,
                last_name=user.last_name,
                phone=getattr(user, 'phone', None),
                role=role,
                joined_at=joined_at,
                is_active=True,
                is_bot=hasattr(user, 'bot') and user.bot,
                last_seen=datetime.now() if hasattr(user, 'status') else None
            )
            
            # 如果成员已存在且状态不同，更新状态
            if existing_member:
                if not existing_member.is_active:
                    member_data.is_active = True
                    member_data.left_at = None
                member_data.created_at = existing_member.created_at
            
            # 保存成员信息
            await self._db_run(self.db.save_group_member, member_data)
            
            logger.debug(f"处理群组成员: chat={chat_id}, member={user.id}, role={role}")
            
            return member_data
            
        except Exception as e:
            logger.error(f"处理群组成员失败 chat={chat_id}, user={user.id}: {e}")
            return None
    """同步群组成员信息（增强版）"""
    async def _sync_group_members(self, chat_entity, chat_id: str) -> int:
        
        try:
            logger.info(f"开始同步群组成员: chat={chat_id}")
            
            # 检查是否需要同步（缓存机制）
            cache_key = f"members_sync_time:{chat_id}"
            last_sync_time = await self.cache.get(cache_key)
            
            # 如果24小时内同步过，跳过
            if last_sync_time:
                last_sync = datetime.fromisoformat(last_sync_time)
                if datetime.now() - last_sync < timedelta(hours=24):
                    logger.debug(f"群组成员最近已同步，跳过: {chat_id}")
                    
                    # 获取当前成员数量
                    count = await self._db_run(self.db.get_group_member_count, chat_id, active_only=True)
                    return count
            
            # 获取群组成员
            participants = []
            actual_count = 0
            
            try:
                
                # 先获取总数
                try:
                    await self._wait_for_api_slot("get_participants", min_interval=0.9)
                    async with self._conn_guard():
                        participants_obj = await self.client.get_participants(chat_entity, limit=1)
                    logger.info(f"群组成员总数: participants_obj = {participants_obj}")
                    if hasattr(participants_obj, 'total'):
                        actual_count = participants_obj.total
                        logger.info(f"群组成员总数: {chat_id} = {actual_count}")
                except Exception:
                    pass
                
                # 分批获取成员
                batch_size = 200
                processed = 0
                
                async with self._conn_guard():
                    await self._wait_for_api_slot("iter_participants", min_interval=1.2)
                    async for participant in self.client.iter_participants(
                        chat_entity, 
                        aggressive=False,
                        limit=batch_size * 3  # 限制总数，避免超大群组
                    ):
                        participants.append(participant)
                        processed += 1
                        
                        # 每批处理一次，避免内存过大
                        if len(participants) >= batch_size:
                            await self._process_members_batch(participants, chat_id)
                            participants = []
                            
                        # 避免请求过快
                        if processed % 50 == 0:
                            await asyncio.sleep(0.5)
              
            except Exception as e:
                logger.warning(f"获取群组成员失败 {chat_id}: {e}")
                return 0
            
            # 处理最后一批
            if participants:
                await self._process_members_batch(participants, chat_id)
            
            # 获取实际处理的数量
            #actual_count = await self._db_run(self.db.get_group_member_count, chat_id, active_only=True)
            
            # 更新缓存同步时间
            await self.cache.set(cache_key, datetime.now().isoformat(), 86400)  # 24小时缓存
            
            # 更新聊天表中的参与者数量
            '''
            await self._db_run(
                self.db.update_chat_participants,
                chat_id=chat_id,
                account_id=self.account_id,
                participants_count=actual_count,
                last_message_time=datetime.now()
            )
            '''
            # 更新聊天缓存
            chat_data = await self._db_run(self.db.get_chat_by_id, chat_id, self.account_id)
            if chat_data:
                chat_data.participants_count = actual_count
                await self._update_chat_cache(chat_data, chat_id)
            
            logger.info(f"群组成员同步完成: chat={chat_id}, 成员数={actual_count}")
            
            return actual_count
            
        except Exception as e:
            logger.error(f"同步群组成员失败 {chat_id}: {e}")
            return 0
    """批量处理群组成员"""
    async def _process_members_batch(self, participants: List, chat_id: str):
        
        try:
            # 批量保存成员
            member_tasks = []
            for participant in participants:
                # 判断成员角色
                role = 'member'
                if hasattr(participant, 'participant'):
                    participant_type = type(participant.participant).__name__
                    if 'Admin' in participant_type:
                        role = 'admin'
                    elif 'Creator' in participant_type:
                        role = 'creator'
                
                # 创建成员模型
                member_data = GroupMemberModel(
                    member_id=participant.id,
                    chat_id=chat_id,
                    username=participant.username,
                    first_name=participant.first_name,
                    last_name=participant.last_name,
                    phone=getattr(participant, 'phone', None),
                    role=role,
                    joined_at=datetime.now(),  # 默认加入时间
                    is_active=True,
                    is_bot=hasattr(participant, 'bot') and participant.bot,
                    last_seen=datetime.now() if hasattr(participant, 'status') else None
                )
                
                # 异步保存
                task = asyncio.create_task(
                    self._db_run(self.db.save_group_member, member_data)
                )
                member_tasks.append(task)
            
            # 等待批量保存完成
            if member_tasks:
                await asyncio.gather(*member_tasks, return_exceptions=True)
                
            logger.debug(f"批量处理群组成员完成: chat={chat_id}, 数量={len(participants)}")
            
        except Exception as e:
            logger.error(f"批量处理群组成员失败 chat={chat_id}: {e}")
    """处理成员加入"""        
    async def _handle_member_join(self, chat, user, chat_id: str):
        
        try:
            logger.info(f"成员加入: chat={chat_id}, user={user.id}")
            
            # 处理成员信息
            member = await self._process_group_member(
                user, 
                chat_id, 
                role='member',  # 默认角色，后续可更新
                joined_at=datetime.now()
            )
            
            if member:
                # 更新聊天参与者数量
                #await self._update_chat_participants_count(chat, change=1, user=user)
                
                # 触发成员加入事件
                await self._trigger_event('participants_update', {
                    'type': 'member_joined',
                    'chat_id': chat_id,
                    'member_id': user.id,
                    'member_name': self._get_user_display_name(user),
                    'username': user.username,
                    'is_bot': hasattr(user, 'bot') and user.bot,
                    'timestamp': datetime.now(),
                    'member_data': member.to_dict()
                })
            
            return member
            
        except Exception as e:
            logger.error(f"处理成员加入失败 chat={chat_id}, user={user.id}: {e}")
            return None
    """处理成员离开"""
    async def _handle_member_left(self, chat, user, chat_id: str, is_kicked: bool = False):
        
        try:
            logger.info(f"成员离开: chat={chat_id}, user={user.id}, kicked={is_kicked}")
            
            # 更新成员状态
            updated = await self._db_run(
                self.db.update_member_status,
                user.id, chat_id, 
                is_active=False,
                left_at=datetime.now()
            )
            
            if updated:
                # 更新聊天参与者数量
                change = -1
                #await self._update_chat_participants_count(chat, change=change, user=user)
                
                # 触发成员离开事件
                await self._trigger_event('participants_update', {
                    'type': 'member_left',
                    'chat_id': chat_id,
                    'member_id': user.id,
                    'member_name': self._get_user_display_name(user),
                    'username': user.username,
                    'is_bot': hasattr(user, 'bot') and user.bot,
                    'is_kicked': is_kicked,
                    'timestamp': datetime.now()
                })
            
            return updated
            
        except Exception as e:
            logger.error(f"处理成员离开失败 chat={chat_id}, user={user.id}: {e}")
            return False    
        
    # ==================== 下载 ====================
    async def _download_user_avatar(self, user, is_account=False) -> Optional[str]:
        """下载用户头像"""
        try:
            if not hasattr(user, 'photo') or not user.photo:
                return None
            
            # 确定存储路径
            if is_account:
                avatar_dir = os.path.join(self.avatar_dir, "account")
                filename = "account_avatar.jpg"
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
            async with self._conn_guard():
                photo_bytes = await self.client.download_profile_photo(user, file=bytes)
            if photo_bytes:
                with open(avatar_path, "wb") as f:
                    f.write(photo_bytes)
                return avatar_path
            
            return None
            
        except Exception as e:
            logger.error(f"下载用户头像失败 {user.id}: {e}")
            return None
    
    async def _download_group_avatar(self, group) -> Optional[str]:
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
            async with self._conn_guard():
                photo_bytes = await self.client.download_profile_photo(group, file=bytes)
            if photo_bytes:
                with open(avatar_path, "wb") as f:
                    f.write(photo_bytes)
                return avatar_path
            
            return None
            
        except Exception as e:
            logger.error(f"下载群组头像失败 {group.id}: {e}")
            return None
    
    async def _download_media(self, message, chat_id) -> Optional[str]:
        """下载媒体文件（仅私聊下载图片）"""
        try:
            if not message.media:
                return None
            
            # 检查消息类型
            is_private = hasattr(message, 'is_private') and message.is_private
            
            # 如果不是私聊，不下载
            if not is_private:
                logger.debug(f"群聊消息，跳过媒体下载: chat={chat_id}, msg={message.id}")
                return None
            
            # 如果是私聊，只下载图片
            is_photo = hasattr(message.media, 'photo')
            if not is_photo:
                logger.debug(f"私聊非图片媒体，跳过下载: chat={chat_id}, msg={message.id}")
                return None
            
            # 创建私聊媒体目录
            chat_media_dir = os.path.join(self.media_dir, "private", str(chat_id))
            os.makedirs(chat_media_dir, exist_ok=True)
            
            # 检查是否已下载
            for file in os.listdir(chat_media_dir):
                if file.startswith(f"{message.id}_"):
                    file_path = os.path.join(chat_media_dir, file)
                    logger.debug(f"媒体文件已存在: {file_path}")
                    return file_path
            
            # 生成文件名
            timestamp = int(time.time())
            random_str = ''.join(random.choices('abcdefghijklmnopqrstuvwxyz0123456789', k=6))
            file_extension = self._get_media_extension(message)
            filename = f"{message.id}_{timestamp}_{random_str}.{file_extension}"
            media_path = os.path.join(chat_media_dir, filename)
            
            # 下载图片
            async with self._conn_guard():
                await self.client.download_media(message.media, file=media_path)
            
            logger.info(f"私聊图片下载完成: chat={chat_id}, msg={message.id}, path={media_path}")
            return media_path
            
        except Exception as e:
            logger.error(f"下载媒体文件失败 {message.id}: {e}")
            return None
    
    def _get_media_extension(self, message) -> str:
        """获取媒体文件扩展名"""
        if hasattr(message.media, 'photo'):
            return "jpg"
        elif hasattr(message.media, 'voice'):
            return "ogg"
        elif hasattr(message.media, 'video'):
            return "mp4"
        elif hasattr(message.media, 'document'):
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
    
    def _get_message_type(self, msg) -> str:
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
