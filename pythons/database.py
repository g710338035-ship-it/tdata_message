# database.py
import aiomysql
import asyncio
import logging
import json
import random
from typing import List, Dict,  Optional
from datetime import datetime
from config import Config
from models import  MessageModel, ChatModel, GroupMemberModel

logger = logging.getLogger(__name__)

class MySQLDatabase:
    """MySQL数据库管理"""
    # 进程级连接池，每个进程独立
    
    def __init__(self, account_id: str = None):
        self.account_id = account_id
        self.pool = None
        self._pool_lock = asyncio.Lock()
        
    async def initialize(self):
        """初始化数据库连接池"""
        async with self._pool_lock:  # 添加锁
            try:
                if self.pool and not self.pool.closed:
                    return  # 已经初始化了
                
                self.pool = await aiomysql.create_pool(**Config.get_mysql_config())
                logger.info("MySQL数据库连接池初始化成功")
            except Exception as e:
                logger.error(f"MySQL数据库连接池初始化失败: {str(e)}")
                raise
    
    async def close(self):
        """关闭数据库连接池"""
        if self.pool:
            self.pool.close()
            await self.pool.wait_closed()
            logger.info("MySQL数据库连接池已关闭")
            
    # ==================== 更新账户状态 ====================
    async def update_mtuser_status_by_session(self, session_path: str, online: int, account_status: str, account_status_desc: str) -> bool:
        try:
            if not session_path:
                return False
            if not self.pool:
                await self.initialize()
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.execute(
                        """
                        UPDATE cd_mtuser SET online = %s, account_status = %s, account_status_desc = %s, updatetime = UNIX_TIMESTAMP() WHERE session_path = %s
                        """,
                        (online, account_status, account_status_desc, session_path)
                    )
                    await conn.commit()
                    return True
        except Exception as e:
            logger.error(f"更新账号状态失败: {str(e)}")
            return False
   
    # ==================== 更新账户状态 ====================
    async def update_mtuser_status_by_phone(self, phone: str, status: int, account_status: str, account_status_desc: str) -> bool:
        try:
            if not phone:
                return False
            if not self.pool:
                await self.initialize()
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.execute(
                        """
                        UPDATE cd_mtuser SET status = %s, account_status = %s, account_status_desc = %s, updatetime = UNIX_TIMESTAMP() WHERE account = %s
                        """,
                        (status, account_status, account_status_desc, phone)
                    )
                    await conn.commit()
                    return True
        except Exception as e:
            logger.error(f"更新账号状态失败: {str(e)}")
            return False
    # ==================== 更新账户未读消息 ====================
    async def update_mtuser_unread_by_phone(self, phone: str, unread: int, groups_count: int, friends_count: int) -> bool:
        try:
            if not phone:
                return False
            if not self.pool:
                await self.initialize()
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.execute(
                        """
                        UPDATE cd_mtuser SET unread = %s,groups_count = %s,friends_count = %s, updatetime = UNIX_TIMESTAMP() WHERE account = %s
                        """,
                        (unread,groups_count,friends_count, phone)
                    )
                    await conn.commit()
                    return True
        except Exception as e:
            logger.error(f"更新未读失败: {str(e)}")
            return False
   

    async def update_chat_participants_count(self, chat_id: str, account_id: str, participants_count: int) -> bool:
        """更新群组人数"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            query = """
            UPDATE cd_tdchats 
            SET participants_count = %s, updated_at = NOW() 
            WHERE chat_id = %s AND account_id = %s
            """
            
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.execute(query, (participants_count, chat_id, account_id))
                    await conn.commit()
                    
                    return cursor.rowcount > 0
                    
        except Exception as e:
            self.logger.error(f"更新群组人数失败 {chat_id}: {str(e)}")
            return False        
    
    # ==================== 聊天会话操作 ====================
        
    async def get_chat(self, chat_id: int, account_id: str = None) -> Optional[ChatModel]:
        """获取单个聊天会话"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            account_id = account_id or self.account_id
            async with self.pool.acquire() as conn:
                async with conn.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute('''
                        SELECT * FROM cd_tdchats 
                        WHERE chat_id = %s AND account_id = %s
                    ''', (chat_id, account_id))
                    row = await cursor.fetchone()
                    if row:
                        return ChatModel(**row)
                    return None
        except Exception as e:
            logger.error(f"获取聊天失败 {chat_id}: {str(e)}")
            return None 
    #  获取单个消息         
    async def get_message(self, message_id: int, chat_id: int, account_id: str = None) -> Optional[MessageModel]:
        """获取单个消息"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            account_id = account_id or self.account_id
            async with self.pool.acquire() as conn:
                async with conn.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute('''
                        SELECT * FROM cd_tdmessages 
                        WHERE message_id = %s AND chat_id = %s AND account_id = %s
                    ''', (message_id, chat_id, account_id))
                    row = await cursor.fetchone()
                    if row:
                        return MessageModel(**row)
                    return None
        except Exception as e:
            logger.error(f"获取消息失败 {message_id}: {str(e)}")
            return None
     
    
    # ==================== 保存消息操作 ====================
    
    async def save_message(self, message: MessageModel, max_per_chat: int = 500) -> bool:
        """保存消息，并确保每个聊天最多只保留指定数量的消息"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        max_retries = 3
        for attempt in range(max_retries):
            async with self.pool.acquire() as conn:
                try:
                    async with conn.cursor() as cursor:
                        await cursor.execute("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED")
                        await conn.begin()

                        await cursor.execute('''
                        INSERT INTO cd_tdmessages (
                            message_id, chat_id, account_id, sender_id,
                            sender_name, sender_username, message_text, message_type,
                            media_path, is_outgoing, reply_to_msg_id, timestamp, is_read, created_at
                        )
                        VALUES (
                            %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s
                        ) AS new
                        ON DUPLICATE KEY UPDATE
                            sender_name     = COALESCE(new.sender_name, cd_tdmessages.sender_name),
                            message_text    = COALESCE(new.message_text, cd_tdmessages.message_text),
                            message_type    = COALESCE(new.message_type, cd_tdmessages.message_type),
                            media_path      = COALESCE(new.media_path, cd_tdmessages.media_path),
                            is_outgoing     = COALESCE(new.is_outgoing, cd_tdmessages.is_outgoing),
                            reply_to_msg_id = COALESCE(new.reply_to_msg_id, cd_tdmessages.reply_to_msg_id),
                            timestamp       = COALESCE(new.timestamp, cd_tdmessages.timestamp),
                            is_read         = COALESCE(new.is_read, cd_tdmessages.is_read);
                        ''', (
                        message.message_id, 
                        message.chat_id, 
                        message.account_id,
                        message.sender_id,
                        self._truncate_field(message.sender_name, 100),  # 限制长度
                        self._truncate_field(message.sender_username, 100),
                        self._truncate_field(message.message_text, 2000),  # 限制文本长度
                        self._truncate_field(message.message_type, 20),
                        self._truncate_field(message.media_path, 500),
                        message.is_outgoing,
                        message.reply_to_msg_id,
                        message.timestamp,
                        message.is_read,
                        message.created_at or datetime.now()
                        ))

                        inserted_new = cursor.rowcount == 1
                        if inserted_new:
                            await cursor.execute('''
                                SELECT COUNT(*) as msg_count
                                FROM cd_tdmessages 
                                WHERE chat_id = %s AND account_id = %s
                            ''', (message.chat_id, message.account_id))

                            result = await cursor.fetchone()
                            current_count = result[0] if result else 0
                            overflow = max(0, current_count - max_per_chat)
                            if overflow:
                                await cursor.execute('''
                                    DELETE FROM cd_tdmessages
                                    WHERE chat_id = %s AND account_id = %s
                                    ORDER BY timestamp ASC, message_id ASC
                                    LIMIT %s
                                ''', (message.chat_id, message.account_id, overflow))

                        await conn.commit()
                        return True

                except Exception as e:
                    try:
                        await conn.rollback()
                    except Exception:
                        pass

                    errno = None
                    if getattr(e, 'args', None) and isinstance(e.args, (list, tuple)) and e.args:
                        if isinstance(e.args[0], int):
                            errno = e.args[0]
                    if errno in (1213, 1205) and attempt < max_retries - 1:
                        await asyncio.sleep(random.uniform(0.05, 0.25) * (attempt + 1))
                        continue

                    logger.error(f"保存消息失败: {str(e)}")
                    return False
        return False
    # 在database.py中添加：
    async def update_chat_participants(self, chat_id: str, account_id: str,participants_count: int, last_message_time: datetime):
        """更新聊天参与者数量""" 
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.execute(
                        """
                        UPDATE cd_tdchats 
                        SET participants_count = %s,
                            updated_at = %s
                        WHERE account_id = %s AND chat_id = %s
                        """,
                        (participants_count, datetime.now(), account_id, chat_id)   
                    )
                    await conn.commit()
                    
            logger.debug(f"更新聊天参与者数量成功: chat_id={chat_id}, account_id={account_id}, participants_count={participants_count}")
            return True
            
        except Exception as e:
            logger.error(f"更新聊天参与者数量失败 {chat_id}: {str(e)}") 
            return False    


        
    def _truncate_field(self, value: str, max_length: int) -> str:
        """截断字段值，防止过长"""
        if not value or not isinstance(value, str):
            return value or ''
        if len(value) <= max_length:
            return value
        return value[:max_length]
   
    
    # ==================== 聊天操作 ====================
    
    async def save_chat(self, chat: ChatModel) -> bool:
        """保存聊天会话（防止重复数据）"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    # 先检查是否存在
                    await cursor.execute(
                        "SELECT 1 FROM cd_tdchats WHERE chat_id = %s AND account_id = %s",
                        (chat.chat_id, chat.account_id)
                    )
                    exists = await cursor.fetchone()
                    
                    if exists:
                        # 存在则更新
                        await cursor.execute('''
                            UPDATE cd_tdchats SET
                            chat_type = %s, title = %s, username = %s, unread_count = %s,
                            last_message_id = %s, last_message_time = %s, avatar_path = %s,
                            participants_count = %s, updated_at = %s
                            WHERE chat_id = %s AND account_id = %s
                        ''', (
                            chat.chat_type, chat.title, chat.username,
                            chat.unread_count, chat.last_message_id, chat.last_message_time,
                            chat.avatar_path, chat.participants_count, chat.updated_at,
                            chat.chat_id, chat.account_id
                        ))
                    else:
                        # 不存在则插入
                        await cursor.execute('''
                            INSERT INTO cd_tdchats 
                            (chat_id, account_id, chat_type, title,username, unread_count, 
                             last_message_id, last_message_time, avatar_path, 
                             participants_count, created_at, updated_at)
                            VALUES (%s, %s, %s, %s, %s, %s,%s, %s, %s, %s, %s, %s)
                        ''', (
                            chat.chat_id, chat.account_id, chat.chat_type, chat.title, chat.username,
                            chat.unread_count, chat.last_message_id, chat.last_message_time,
                            chat.avatar_path, chat.participants_count,
                            chat.created_at, chat.updated_at
                        ))
                    
                    await conn.commit()
                    return True
        except Exception as e:
            logger.error(f"保存聊天失败: {str(e)}")
            return False
    # ==================== 聊天操作 ====================    
    async def get_chats(self, limit: int = 100, offset: int = 0,account_id: str = None) -> List[ChatModel]:
        """获取聊天会话"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            account_id = account_id or self.account_id
            async with self.pool.acquire() as conn:
                async with conn.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute('''
                        SELECT * FROM cd_tdchats 
                        WHERE account_id = %s 
                        ORDER BY last_message_time DESC 
                        LIMIT %s OFFSET %s
                    ''', (account_id, limit, offset))
                    rows = await cursor.fetchall()
                    return [ChatModel(**row) for row in rows]
        except Exception as e:
            logger.error(f"获取聊天失败: {str(e)}")
            return []
    
      
    
    # ============================================
    # 批量保存聊天信息方法
    # ============================================
    async def batch_save_chats(self, chats: List[Dict]):
        """批量保存聊天信息"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            if not chats:
                return
            
            # 这里需要根据您的表结构实现具体的保存逻辑
            # 示例SQL（请根据实际表结构调整）
            sql = """
            INSERT INTO cd_tdchats (
                account_id, chat_id, chat_type, title, username,
                unread_count, participants_count, chat_data, synced_at
            )
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s) AS new
            ON DUPLICATE KEY UPDATE
                title              = new.title,
                username           = new.username,
                unread_count       = new.unread_count,
                participants_count = new.participants_count,
                chat_data          = new.chat_data,
                synced_at          = new.synced_at;
            """
            
            values = []
            for chat in chats:
                values.append((
                    chat.get('account_id'),
                    chat.get('chat_id'),
                    chat.get('chat_type'),
                    chat.get('title'),
                    chat.get('username'),
                    chat.get('unread_count', 0),
                    chat.get('participants_count', 0),
                    json.dumps(chat.get('chat_data', {})) if chat.get('chat_data') else None,
                    chat.get('synced_at')
                ))
            
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.executemany(sql, values)
                    await conn.commit()
            
            logger.info(f"批量保存了 {len(chats)} 个聊天信息")
            
        except Exception as e:
            logger.error(f"批量保存聊天信息失败: {str(e)}")
            raise
    # ============================================
    # 批量更新聊天未读数方法
    # ============================================
    async def batch_update_chat_unread(self, chats: List[Dict]):
        """批量更新聊天未读数"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            if not chats:
                return
            
            # 这里需要根据您的表结构实现具体的更新逻辑
            sql = """
            UPDATE cd_tdchats 
            SET unread_count = %s, last_message_time = %s, updated_at = %s, participants_count= %s
            WHERE account_id = %s AND chat_id = %s
            """
            
            values = []
            for chat in chats:
                values.append((
                    chat.get('unread_count', 0),
                    chat.get('last_message_time'),
                    chat.get('updated_at'),
                    chat.get('participants_count'),
                    chat.get('account_id'),
                    chat.get('chat_id')
                ))
            
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.executemany(sql, values)
                    await conn.commit()
            
            logger.info(f"批量更新了 {len(chats)} 个聊天的未读数")
            
        except Exception as e:
            logger.error(f"批量更新聊天未读数失败: {str(e)}")
            raise   
    
    async def get_chat_by_id(self, chat_id: str, account_id: str):
        """根据聊天ID获取聊天信息"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    # 按照ChatModel的字段顺序查询
                    await cursor.execute(
                        """
                        SELECT 
                            chat_id, account_id, chat_type, title,
                            unread_count, is_bot, chat_data, id,
                             username, last_message_id, last_message_time,
                            avatar_path, participants_count, created_at, updated_at
                        FROM cd_tdchats 
                        WHERE chat_id = %s AND account_id = %s
                        LIMIT 1
                        """,
                        (chat_id, account_id)
                    )
                    result = await cursor.fetchone()
                    
                    if result:
                        # 字段名列表必须与SELECT顺序完全一致
                        field_names = [
                            'chat_id', 'account_id', 'chat_type', 'title',
                            'unread_count', 'is_bot', 'chat_data', 'id',
                             'username', 'last_message_id', 'last_message_time',
                            'avatar_path', 'participants_count', 'created_at', 'updated_at'
                        ]
                        
                        # 创建字典
                        result_dict = dict(zip(field_names, result))
                        
                        # 调试：查看participants_count值
                        logger.debug(f"从数据库获取: chat_id={chat_id}, "
                                    f"participants_count={result_dict.get('participants_count')}")
                        
                        # 转换为ChatModel
                        return ChatModel.from_dict(result_dict)
                    
            return None
        except Exception as e:
            logger.error(f"获取聊天信息失败 {chat_id}: {str(e)}")
            return None
            
    async def update_chat_unread(self, chat_id: str, account_id: str,unread_count: int, last_message_time: datetime = None):
        """更新聊天未读数"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    update_fields = ["unread_count = %s", "updated_at = %s"]
                    update_values = [unread_count, datetime.now()]
                    
                    if last_message_time:
                        update_fields.append("last_message_time = %s")
                        update_values.append(last_message_time)
                    
                    update_values.extend([account_id, chat_id])
                    
                    await cursor.execute(
                        f"""
                        UPDATE cd_tdchats 
                        SET {', '.join(update_fields)}
                        WHERE account_id = %s AND chat_id = %s
                        """,
                        update_values
                    )
                    await conn.commit()
                    
            logger.debug(f"更新聊天未读数: chat_id={chat_id}, unread={unread_count}")
            return True
            
        except Exception as e:
            logger.error(f"更新聊天未读数失败 {chat_id}: {str(e)}")
            return False
    
    async def update_chat_last_message(self, chat_id: str, account_id: str,last_message_id: int, last_message_time: datetime):
        """更新聊天最后消息信息"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.execute(
                        """
                        UPDATE cd_tdchats 
                        SET last_message_id = %s, 
                            last_message_time = %s,
                            updated_at = %s
                        WHERE account_id = %s AND chat_id = %s
                        """,
                        (last_message_id, last_message_time, datetime.now(), 
                         account_id, chat_id)
                    )
                    await conn.commit()
                    
            logger.debug(f"更新聊天最后消息: chat_id={chat_id}, msg_id={last_message_id}")
            return True
            
        except Exception as e:
            logger.error(f"更新聊天最后消息失败 {chat_id}: {str(e)}")
            return False
    
    async def increment_chat_unread(self, chat_id: str, account_id: str):
        """聊天未读数加1"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.execute(
                        """
                        UPDATE cd_tdchats 
                        SET unread_count = unread_count + 1,
                            updated_at = %s
                        WHERE account_id = %s AND chat_id = %s
                        """,
                        (datetime.now(), account_id, chat_id)
                    )
                    await conn.commit()
                    
            logger.debug(f"聊天未读数加1: chat_id={chat_id}")
            return True
            
        except Exception as e:
            logger.error(f"聊天未读数加1失败 {chat_id}: {str(e)}")
            return False    
    async def increment_chats_unread(self, chat_id: str, account_id: str,count: int,):
        """聊天未读数加1"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.execute(
                        """
                        UPDATE cd_tdchats 
                        SET unread_count = unread_count + %s,
                            updated_at = %s
                        WHERE account_id = %s AND chat_id = %s
                        """,
                        (count, datetime.now(), account_id, chat_id)
                    )
                    await conn.commit()
                    
            logger.debug(f"聊天未读数加{count}: chat_id={chat_id}")
            return True
            
        except Exception as e:
            logger.error(f"聊天未读数加{count}失败 {chat_id}: {str(e)}")
            return False    

    # 使用这个版本，最安全
    async def save_group_member(self, member: GroupMemberModel):
        """保存群组成员信息（推荐使用字典参数）"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    sql = """
                        INSERT INTO cd_group_members (
                            member_id, chat_id, username,
                            first_name, last_name, phone, role,
                            joined_at, left_at, is_active, is_bot,
                            last_seen, created_at, updated_at
                        )
                        VALUES (
                            %(member_id)s, %(chat_id)s, %(username)s,
                            %(first_name)s, %(last_name)s, %(phone)s, %(role)s,
                            %(joined_at)s, %(left_at)s, %(is_active)s, %(is_bot)s,
                            %(last_seen)s, %(created_at)s, %(updated_at)s
                        ) AS new
                        ON DUPLICATE KEY UPDATE
                            username    = new.username,
                            first_name  = new.first_name,
                            last_name   = new.last_name,
                            phone       = new.phone,
                            role        = new.role,
                            left_at     = new.left_at,
                            is_active   = new.is_active,
                            is_bot      = new.is_bot,
                            last_seen   = new.last_seen,
                            updated_at  = new.updated_at;
                    """
                    
                    params = member.to_dict()
                    
                    await cursor.execute(sql, params)
                    await conn.commit()
                    
            logger.info(f"群组成员保存成功: {member.chat_id}:{member.member_id}")
                    
        except Exception as e:
            logger.error(f"保存群组成员失败: {e}")
            raise
    
    async def get_group_member(self, member_id: int, chat_id: str) -> Optional[GroupMemberModel]:
        """获取群组成员信息"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.execute(
                        """
                        SELECT 
                            member_id, chat_id, username,
                            first_name, last_name, phone, role,
                            joined_at, left_at, is_active, is_bot,
                            last_seen, created_at, updated_at
                        FROM cd_group_members 
                        WHERE member_id = %s AND chat_id = %s
                        LIMIT 1
                        """,
                        (member_id, chat_id)
                    )
                    result = await cursor.fetchone()
                    
                    if result:
                        field_names = [
                            'member_id', 'chat_id', 'username',
                            'first_name', 'last_name', 'phone', 'role',
                            'joined_at', 'left_at', 'is_active', 'is_bot',
                            'last_seen', 'created_at', 'updated_at'
                        ]
                        
                        result_dict = dict(zip(field_names, result))
                        return GroupMemberModel.from_dict(result_dict)
                    
            return None
        except Exception as e:
            logger.error(f"获取群组成员失败 member={member_id}, chat={chat_id}: {str(e)}")
            return None
    
    async def get_group_members(self, chat_id: str, 
                                limit: int = 100, offset: int = 0,
                                active_only: bool = True,
                                role: Optional[str] = None) -> List[GroupMemberModel]:
        """获取群组成员列表"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    query = """
                        SELECT 
                            member_id, chat_id, username,
                            first_name, last_name, phone, role,
                            joined_at, left_at, is_active, is_bot,
                            last_seen, created_at, updated_at
                        FROM cd_group_members 
                        WHERE chat_id = %s
                    """
                    params = [chat_id]
                    
                    if active_only:
                        query += " AND is_active = TRUE"
                    
                    if role:
                        query += " AND role = %s"
                        params.append(role)
                    
                    query += " ORDER BY joined_at DESC LIMIT %s OFFSET %s"
                    params.extend([limit, offset])
                    
                    await cursor.execute(query, tuple(params))
                    results = await cursor.fetchall()
                    
                    members = []
                    field_names = [
                        'member_id', 'chat_id', 'username',
                        'first_name', 'last_name', 'phone', 'role',
                        'joined_at', 'left_at', 'is_active', 'is_bot',
                        'last_seen', 'created_at', 'updated_at'
                    ]
                    
                    for result in results:
                        result_dict = dict(zip(field_names, result))
                        members.append(GroupMemberModel.from_dict(result_dict))
                    
                    return members
        except Exception as e:
            logger.error(f"获取群组成员列表失败 chat={chat_id}: {str(e)}")
            return []
    
    async def update_member_status(self, member_id: int, chat_id: str,is_active: bool = False, left_at: Optional[datetime] = None):
        """更新成员状态（活跃/离开）"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    await cursor.execute(
                        """
                        UPDATE cd_group_members 
                        SET is_active = %s, 
                            left_at = %s,
                            updated_at = NOW()
                        WHERE member_id = %s AND chat_id = %s
                        """,
                        (is_active, left_at, member_id, chat_id)
                    )
                    await conn.commit()
                    
                    action = "设为活跃" if is_active else "设为离开"
                    logger.info(f"成员状态更新: {action}, chat={chat_id}, member={member_id}")
                    
                    return cursor.rowcount > 0
        except Exception as e:
            logger.error(f"更新成员状态失败 chat={chat_id}, member={member_id}: {str(e)}")
            return False
    
    async def get_group_member_count(self, chat_id: str, active_only: bool = True) -> int:
        """获取群组成员数量"""
        # 检查连接池
        if not self.pool or self.pool.closed:
            await self.initialize()
        try:
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cursor:
                    query = """
                        SELECT COUNT(*) 
                        FROM cd_group_members 
                        WHERE chat_id = %s
                    """
                    params = [chat_id]
                    
                    if active_only:
                        query += " AND is_active = TRUE"
                    
                    await cursor.execute(query, tuple(params))
                    result = await cursor.fetchone()
                    
                    return result[0] if result else 0
        except Exception as e:
            logger.error(f"获取群组成员数量失败 chat={chat_id}: {str(e)}")
            return 0        
