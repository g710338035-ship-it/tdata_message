# telethon_fix.py
# Telethon错误修复工具

import os
import logging
import asyncio
from typing import Optional, Dict, Any
from telethon import TelegramClient
from telethon.errors import (
    ValueError, 
    FloodWaitError, 
    RPCError
)
from telethon.tl.types import PeerUser, PeerChat, PeerChannel
from telethon.tl.functions.messages import GetDialogsRequest
from telethon.tl.functions.contacts import GetContactsRequest

logger = logging.getLogger(__name__)

class TelethonErrorFixer:
    """Telethon错误修复工具类"""
    
    def __init__(self, client: TelegramClient, account_id: str, session_path: str):
        self.client = client
        self.account_id = account_id
        self.session_path = session_path
        
    async def fix_session_permissions(self):
        """修复会话文件权限问题"""
        try:
            # 检查会话文件是否存在
            if os.path.exists(self.session_path):
                # 尝试修改文件权限
                try:
                    os.chmod(self.session_path, 0o644)  # 读写权限
                    logger.info(f"已修复会话文件权限: {self.session_path}")
                except Exception as e:
                    logger.warning(f"无法修改会话文件权限: {e}")
                    
                # 检查文件是否可写
                if not os.access(self.session_path, os.W_OK):
                    logger.warning(f"会话文件不可写: {self.session_path}")
                    # 尝试创建备份并重新初始化
                    await self._recreate_session_file()
            else:
                logger.warning(f"会话文件不存在: {self.session_path}")
                
        except Exception as e:
            logger.error(f"修复会话权限失败: {e}")
    
    async def _recreate_session_file(self):
        """重新创建会话文件"""
        try:
            # 备份原文件
            backup_path = f"{self.session_path}.backup"
            if os.path.exists(self.session_path):
                import shutil
                shutil.copy2(self.session_path, backup_path)
                logger.info(f"已备份会话文件: {backup_path}")
            
            # 重新初始化客户端连接
            await self.client.connect()
            logger.info("客户端重新连接成功")
            
        except Exception as e:
            logger.error(f"重新创建会话文件失败: {e}")
    
    async def fix_entity_not_found(self, user_id: int) -> bool:
        """修复实体未找到错误"""
        try:
            logger.info(f"尝试修复实体未找到错误: PeerUser(user_id={user_id})")
            
            # 方法1: 尝试通过ID直接获取
            try:
                entity = await self.client.get_entity(PeerUser(user_id))
                logger.info(f"通过ID获取实体成功: {entity}")
                return True
            except ValueError:
                logger.info(f"通过ID获取实体失败，尝试其他方法")
            
            # 方法2: 获取对话框列表来"遇到"该实体
            await self._encounter_entity_via_dialogs()
            
            # 方法3: 获取联系人列表
            await self._encounter_entity_via_contacts()
            
            # 再次尝试获取实体
            try:
                entity = await self.client.get_entity(PeerUser(user_id))
                logger.info(f"修复后获取实体成功: {entity}")
                return True
            except ValueError as e:
                logger.error(f"修复后仍然无法获取实体: {e}")
                return False
                
        except Exception as e:
            logger.error(f"修复实体未找到错误失败: {e}")
            return False
    
    async def _encounter_entity_via_dialogs(self):
        """通过获取对话框列表来遇到实体"""
        try:
            dialogs = await self.client.get_dialogs(limit=100)
            logger.info(f"获取了 {len(dialogs)} 个对话框，填充实体缓存")
            
            # 处理每个对话框的实体
            for dialog in dialogs:
                if dialog.entity:
                    # 实体已经被缓存
                    pass
                    
        except Exception as e:
            logger.warning(f"获取对话框失败: {e}")
    
    async def _encounter_entity_via_contacts(self):
        """通过获取联系人列表来遇到实体"""
        try:
            contacts = await self.client(GetContactsRequest(hash=0))
            logger.info(f"获取了 {len(contacts.users)} 个联系人，填充实体缓存")
        except Exception as e:
            logger.warning(f"获取联系人失败: {e}")
    
    async def safe_get_entity(self, entity_id, entity_type="user"):
        """安全的实体获取方法，包含错误处理"""
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
            entity = await self.client.get_entity(peer)
            return entity
            
        except ValueError as e:
            if "Could not find the input entity" in str(e):
                logger.warning(f"实体未找到，尝试修复: {entity_id}")
                # 尝试修复
                if entity_type == "user":
                    fixed = await self.fix_entity_not_found(entity_id)
                    if fixed:
                        # 修复后重试
                        return await self.client.get_entity(peer)
                
                # 如果修复失败，抛出更详细的错误
                raise ValueError(f"无法找到实体 {entity_id} ({entity_type})，请确保该实体在您的联系人列表或对话框中")
            else:
                raise e
        except Exception as e:
            logger.error(f"获取实体时发生未知错误: {e}")
            raise e
    
    async def check_connection_health(self) -> Dict[str, Any]:
        """检查连接健康状态"""
        health = {
            "session_file_writable": False,
            "client_connected": False,
            "ping_successful": False,
            "dialogs_accessible": False
        }
        
        try:
            # 检查会话文件权限
            if os.path.exists(self.session_path):
                health["session_file_writable"] = os.access(self.session_path, os.W_OK)
            
            # 检查客户端连接状态
            health["client_connected"] = self.client.is_connected()
            
            # 测试Ping
            if health["client_connected"]:
                try:
                    await self.client(PingRequest(ping_id=12345))
                    health["ping_successful"] = True
                except:
                    health["ping_successful"] = False
            
            # 测试对话框访问
            if health["ping_successful"]:
                try:
                    dialogs = await self.client.get_dialogs(limit=1)
                    health["dialogs_accessible"] = len(dialogs) >= 0
                except:
                    health["dialogs_accessible"] = False
            
            return health
            
        except Exception as e:
            logger.error(f"检查连接健康状态失败: {e}")
            return health

# 使用示例
async def test_fixer():
    """测试修复工具"""
    # 创建客户端
    client = TelegramClient("test_session", api_id="your_api_id", api_hash="your_api_hash")
    
    # 创建修复器
    fixer = TelethonErrorFixer(client, "test_account", "test_session.session")
    
    # 检查健康状态
    health = await fixer.check_connection_health()
    print("连接健康状态:", health)
    
    # 修复会话权限
    await fixer.fix_session_permissions()
    
    # 测试实体获取
    try:
        entity = await fixer.safe_get_entity(8596810002, "user")
        print("实体获取成功:", entity)
    except Exception as e:
        print("实体获取失败:", e)

if __name__ == "__main__":
    asyncio.run(test_fixer())