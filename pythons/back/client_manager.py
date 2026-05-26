"""
多进程环境下的client连接管理器
确保在多进程环境中client能够长期在线
"""

import os
import asyncio
import time
import hashlib
import logging
from typing import Dict, Optional
import multiprocessing
from concurrent.futures import ProcessPoolExecutor

logger = logging.getLogger(__name__)

class MultiProcessClientManager:
    """多进程client管理器"""
    
    def __init__(self, max_workers: int = None):
        self.max_workers = max_workers or min(32, (os.cpu_count() or 1) + 4)
        self.process_pool: Optional[ProcessPoolExecutor] = None
        self._client_cache: Dict[str, Dict] = {}
        self._cache_lock = asyncio.Lock()
        self._cleanup_task = None
        
    async def init_process_pool(self):
        """初始化进程池"""
        if self.process_pool is None:
            self.process_pool = ProcessPoolExecutor(max_workers=self.max_workers)
            logger.info(f"初始化进程池，最大工作进程数: {self.max_workers}")
            
            # 启动定期清理任务
            self._cleanup_task = asyncio.create_task(self._periodic_cleanup())
    
    async def shutdown(self):
        """关闭管理器"""
        if self._cleanup_task:
            self._cleanup_task.cancel()
            try:
                await self._cleanup_task
            except asyncio.CancelledError:
                pass
        
        if self.process_pool:
            self.process_pool.shutdown(wait=True)
            self.process_pool = None
            logger.info("进程池已关闭")
    
    async def get_client_key(self, tdata_path: str, api_id: int, api_hash: str, proxy_str: Optional[str] = None) -> str:
        """生成client的唯一标识key"""
        normalized_path = os.path.normpath(tdata_path)
        absolute_path = os.path.abspath(normalized_path)
        
        key_data = f"{absolute_path}:{api_id}:{api_hash}:{proxy_str or 'no-proxy'}"
        return hashlib.md5(key_data.encode()).hexdigest()
    
    async def get_or_create_client(self, tdata_path: str, api_id: int, api_hash: str, 
                                 proxy_str: Optional[str] = None, enable_keep_alive: bool = True):
        """获取或创建client实例"""
        key = await self.get_client_key(tdata_path, api_id, api_hash, proxy_str)
        
        async with self._cache_lock:
            if key in self._client_cache:
                client_info = self._client_cache[key]
                
                # 检查client是否仍然有效
                if await self._is_client_valid(client_info):
                    logger.info(f"复用缓存中的client: {key}")
                    return client_info['handler']
                else:
                    # 移除无效的client
                    del self._client_cache[key]
                    logger.info(f"移除无效的client缓存: {key}")
            
            # 创建新的client
            from tdata_processor import TelegramAccountHandler
            handler = TelegramAccountHandler(
                tdata_path=tdata_path,
                api_id=api_id,
                api_hash=api_hash,
                proxy_str=proxy_str
            )
            
            # 设置在线状态并启用保活
            await handler.set_online(enable_keep_alive=enable_keep_alive)
            
            # 缓存client信息
            self._client_cache[key] = {
                'handler': handler,
                'created_time': time.time(),
                'last_used_time': time.time(),
                'use_count': 0
            }
            
            logger.info(f"创建新的client并缓存: {key}")
            return handler
    
    async def _is_client_valid(self, client_info: Dict) -> bool:
        """检查client是否仍然有效"""
        try:
            handler = client_info['handler']
            
            # 检查连接状态
            if not handler.session_cache.get('is_connected', False):
                return False
            
            # 检查client是否可用
            client = handler.session_cache.get('client')
            if client is None:
                return False
            
            # 检查连接是否活跃
            if not client.is_connected():
                return False
            
            # 检查是否超过最大缓存时间（1小时）
            if time.time() - client_info['created_time'] > 3600:
                return False
            
            return True
            
        except Exception as e:
            logger.warning(f"检查client有效性时出错: {str(e)}")
            return False
    
    async def remove_client(self, tdata_path: str, api_id: int, api_hash: str, 
                          proxy_str: Optional[str] = None):
        """移除client缓存"""
        key = await self.get_client_key(tdata_path, api_id, api_hash, proxy_str)
        
        async with self._cache_lock:
            if key in self._client_cache:
                client_info = self._client_cache[key]
                
                # 断开连接
                try:
                    handler = client_info['handler']
                    await handler.set_offline()
                except Exception as e:
                    logger.warning(f"断开client连接时出错: {str(e)}")
                
                del self._client_cache[key]
                logger.info(f"移除client缓存: {key}")
    
    async def _periodic_cleanup(self):
        """定期清理无效的client缓存"""
        while True:
            try:
                # 每5分钟清理一次
                await asyncio.sleep(300)
                
                async with self._cache_lock:
                    keys_to_remove = []
                    current_time = time.time()
                    
                    for key, client_info in self._client_cache.items():
                        # 检查是否超过最大缓存时间（1小时）
                        if current_time - client_info['created_time'] > 3600:
                            keys_to_remove.append(key)
                        # 检查client是否仍然有效
                        elif not await self._is_client_valid(client_info):
                            keys_to_remove.append(key)
                    
                    # 移除无效的client
                    for key in keys_to_remove:
                        try:
                            handler = self._client_cache[key]['handler']
                            await handler.set_offline()
                        except Exception as e:
                            logger.warning(f"清理时断开client连接出错: {str(e)}")
                        
                        del self._client_cache[key]
                        logger.info(f"定期清理移除client缓存: {key}")
                    
                    if keys_to_remove:
                        logger.info(f"定期清理完成，移除了 {len(keys_to_remove)} 个无效client")
                        
            except asyncio.CancelledError:
                break
            except Exception as e:
                logger.error(f"定期清理任务出错: {str(e)}")
                await asyncio.sleep(60)  # 出错后等待1分钟再继续
    
    def get_cache_stats(self) -> Dict:
        """获取缓存统计信息"""
        return {
            'total_clients': len(self._client_cache),
            'cache_keys': list(self._client_cache.keys())
        }

# 全局管理器实例
GLOBAL_CLIENT_MANAGER = MultiProcessClientManager()