# cache.py
from redis import asyncio as aioredis
import json
import asyncio
import logging
from typing import Optional, Any, Dict, List
from datetime import datetime
from config import Config

logger = logging.getLogger(__name__)

class RedisCache:
    """Redis缓存管理"""
        
    # 进程级Redis连接，每个进程独立
    _process_redis = None
    _process_initialized = False
    _lock = asyncio.Lock()
    
    def __init__(self, account_id: str = None):
        self.account_id = account_id
        self.redis = None
        #self._lock = asyncio.Lock()
    
    async def initialize(self):
        """初始化Redis连接"""
        async with self._lock:
            if RedisCache._process_initialized:
                self.redis = RedisCache._process_redis
                return
            
            try:
                # 初始化进程级Redis连接
                RedisCache._process_redis = await aioredis.from_url(
                    f"redis://{Config.REDIS_HOST}:{Config.REDIS_PORT}/{Config.REDIS_DB}",
                    password=Config.REDIS_PASSWORD or None,
                    encoding="utf-8",
                    decode_responses=True
                )
                # 测试连接
                await RedisCache._process_redis.ping()
                RedisCache._process_initialized = True
                self.redis = RedisCache._process_redis
                logger.info("Redis连接初始化成功")
            except Exception as e:
                logger.error(f"Redis连接初始化失败: {str(e)}")
                raise
    
    async def close(self, force: bool = False):
        """关闭Redis连接"""
        if not force:
            self.redis = RedisCache._process_redis
            return
        if RedisCache._process_redis:
            await RedisCache._process_redis.close()
            RedisCache._process_redis = None
            RedisCache._process_initialized = False
            self.redis = None
            logger.info("Redis连接已关闭")
    
    def _get_key(self, key_type: str, *args) -> str:
        """获取完整的缓存键"""
        return Config.get_cache_key(key_type, self.account_id, *args)
    
    async def set(self, key_type: str, value: Any, ttl: int = None, *args) -> bool:
        """设置缓存"""
        try:
            if value is None:
                return False
            
            cache_key = self._get_key(key_type, *args)
            
            # 序列化值
            if isinstance(value, (dict, list)):
                value_str = json.dumps(value, ensure_ascii=False, default=self._json_serializer)
            elif isinstance(value, (int, float, bool)):
                value_str = str(value)
            else:
                value_str = value
            
            # 设置TTL
            if ttl is None:
                ttl = Config.CACHE_TTL.get(key_type, 300)
            
            async with self._lock:
                await self.redis.setex(cache_key, ttl, value_str)
            
            logger.debug(f"缓存设置成功: {cache_key}, TTL: {ttl}s")
            return True
        except Exception as e:
            logger.error(f"设置缓存失败: {str(e)}")
            return False
    
    async def get(self, key_type: str, *args) -> Optional[Any]:
        """获取缓存"""
        try:
            cache_key = self._get_key(key_type, *args)
            
            async with self._lock:
                value = await self.redis.get(cache_key)
            
            if value is None:
                return None
            
            # 尝试反序列化JSON
            try:
                return json.loads(value)
            except json.JSONDecodeError:
                return value
                
        except Exception as e:
            logger.error(f"获取缓存失败: {str(e)}")
            return None
    
    async def delete(self, key_type: str, *args) -> bool:
        """删除缓存"""
        try:
            cache_key = self._get_key(key_type, *args)
            async with self._lock:
                result = await self.redis.delete(cache_key)
            return result > 0
        except Exception as e:
            logger.error(f"删除缓存失败: {str(e)}")
            return False
    
    async def clear_account_cache(self, account_id: str = None) -> bool:
        """清除账号相关缓存"""
        try:
            account_id = account_id or self.account_id
            pattern = f"{Config.REDIS_PREFIX}*:{account_id}:*"
            
            async with self._lock:
                keys = await self.redis.keys(pattern)
                if keys:
                    await self.redis.delete(*keys)
            
            logger.info(f"已清除账号缓存: {account_id}, 删除键数: {len(keys)}")
            return True
        except Exception as e:
            logger.error(f"清除账号缓存失败: {str(e)}")
            return False
    
    async def exists(self, key_type: str, *args) -> bool:
        """检查缓存是否存在"""
        try:
            cache_key = self._get_key(key_type, *args)
            async with self._lock:
                return await self.redis.exists(cache_key) > 0
        except Exception as e:
            logger.error(f"检查缓存失败: {str(e)}")
            return False
    
    async def ttl(self, key_type: str, *args) -> int:
        """获取缓存剩余时间"""
        try:
            cache_key = self._get_key(key_type, *args)
            async with self._lock:
                ttl = await self.redis.ttl(cache_key)
            return ttl
        except Exception as e:
            logger.error(f"获取缓存TTL失败: {str(e)}")
            return -2
    
    async def set_hash(self, key_type: str, field: str,  value: Any,ttl: Optional[int] = None, *args) -> bool:
        """设置哈希字段"""
        try:
            cache_key = self._get_key(key_type, *args)
            
            if isinstance(value, (dict, list)):
                value = json.dumps(value, ensure_ascii=False, default=self._json_serializer)
            
            async with self._lock:
                await self.redis.hset(cache_key, field, value)
                # 设置过期时间
                if ttl is not None:
                    await self.redis.expire(cache_key, ttl)
            return True
            
        except Exception as e:
            logger.error(f"设置哈希字段失败: {str(e)}")
            return False
    
    async def get_hash(self, key_type: str, field: str, *args) -> Optional[Any]:
        """获取哈希字段"""
        try:
            cache_key = self._get_key(key_type, *args)
            
            async with self._lock:
                value = await self.redis.hget(cache_key, field)
            
            if value is None:
                return None
            
            try:
                return json.loads(value)
            except json.JSONDecodeError:
                return value
                
        except Exception as e:
            logger.error(f"获取哈希字段失败: {str(e)}")
            return None
    
    async def get_all_hash(self, key_type: str, *args) -> Dict[str, Any]:
        """获取所有哈希字段"""
        try:
            cache_key = self._get_key(key_type, *args)
            
            async with self._lock:
                data = await self.redis.hgetall(cache_key)
            
            result = {}
            for key, value in data.items():
                try:
                    result[key] = json.loads(value)
                except json.JSONDecodeError:
                    result[key] = value
            
            return result
        except Exception as e:
            logger.error(f"获取所有哈希字段失败: {str(e)}")
            return {}
    
    async def increment(self, key_type: str, amount: int = 1, *args) -> int:
        """递增计数器"""
        try:
            cache_key = self._get_key(key_type, *args)
            async with self._lock:
                return await self.redis.incrby(cache_key, amount)
        except Exception as e:
            logger.error(f"递增计数器失败: {str(e)}")
            return 0
    
    async def add_to_list(self, key_type: str, value: Any, max_length: int = 100,ttl: int = None, *args) -> bool:
        """添加到列表"""
        try:
            cache_key = self._get_key(key_type, *args)
            
            if isinstance(value, (dict, list)):
                value = json.dumps(value, ensure_ascii=False, default=self._json_serializer)
            
            async with self._lock:
                # 添加到列表开头
                await self.redis.lpush(cache_key, value)
                # 限制列表长度
                await self.redis.ltrim(cache_key, 0, max_length - 1)
                # 设置过期时间
                if ttl is None:
                    ttl = Config.CACHE_TTL.get(key_type, 300)
                if ttl is not None:
                    await self.redis.expire(cache_key, ttl)
            return True
        except Exception as e:
            logger.error(f"添加到列表失败: {str(e)}")
            return False
    
    async def get_list(self, key_type: str, start: int = 0, end: int = -1,account_id: str = None, *args) -> List[Any]:
        """获取列表"""
        try:
            # 构建缓存键参数
            key_args = []
            if account_id:
                key_args.append(account_id)
            key_args.extend(args)
            
            cache_key = self._get_key(key_type, *key_args)
            
            async with self._lock:
                items = await self.redis.lrange(cache_key, start, end)
            
            result = []
            for item in items:
                try:
                    result.append(json.loads(item))
                except json.JSONDecodeError:
                    result.append(item)
            
            return result
        except Exception as e:
            logger.error(f"获取列表失败: {str(e)}")
            return []
    
    async def set_expire(self, key_type: str, ttl: int, *args) -> bool:
        """设置过期时间"""
        try:
            cache_key = self._get_key(key_type, *args)
            async with self._lock:
                return await self.redis.expire(cache_key, ttl)
        except Exception as e:
            logger.error(f"设置过期时间失败: {str(e)}")
            return False
    
    def _json_serializer(self, obj):
        """JSON序列化器"""
        if isinstance(obj, datetime):
            return obj.isoformat()
        raise TypeError(f"Type {type(obj)} not serializable")
        
    # 在 RedisCache 类中添加 set_list 方法
    async def set_list(self, key_type: str, values: List[Any], ttl: int = None, account_id: str = None, *args) -> bool:
        """设置列表缓存"""
        try:
            if not values:
                return False
            
            # 构建缓存键参数
            key_args = []
            if account_id:
                key_args.append(account_id)
            key_args.extend(args)
            
            cache_key = self._get_key(key_type, *key_args)
            
            # 清空现有列表
            async with self._lock:
                await self.redis.delete(cache_key)
                
                # 序列化并添加到列表
                for value in values:
                    if isinstance(value, (dict, list)):
                        value_str = json.dumps(value, ensure_ascii=False, default=self._json_serializer)
                    elif isinstance(value, (int, float, bool)):
                        value_str = str(value)
                    else:
                        value_str = value
                    
                    await self.redis.rpush(cache_key, value_str)
            
            # 设置TTL
            if ttl is None:
                # 尝试从配置获取TTL
                if hasattr(Config, 'CACHE_TTL'):
                    ttl = Config.CACHE_TTL.get(key_type, Config.CACHE_TTL.get('default', 300))
                else:
                    ttl = 300
            
            if ttl > 0:
                await self.set_expire(key_type, ttl, *key_args)
            
            logger.debug(f"列表缓存设置成功: {cache_key}, 元素数量: {len(values)}, TTL: {ttl}s")
            return True
            
        except Exception as e:
            logger.error(f"设置列表缓存失败: {str(e)}")
            return False   
            
    async def check_global_event_dedup(self, event_type: str, chat_id: str, user_id: int,
                                       action: str = None, ttl: int = 5) -> bool:
        """
        检查全局事件是否重复（多个账户共享）
        返回 True 表示重复，False 表示新事件
        """
        try:
            # 生成全局事件唯一标识
            event_key = f"global_event:{chat_id}:{user_id}"
            
            # 尝试设置键值，如果键已存在返回False
            result = await self.redis.set(
                event_key, 
                "1", 
                ex=ttl, 
                nx=True  # 只在键不存在时设置
            )
            
            # result is True: 设置成功，键不存在 → 新事件
            # result is None/False: 设置失败，键已存在 → 重复事件
            is_duplicate = result is None or result is False
            
            if is_duplicate:
                logger.debug(f"全局事件重复: {event_key}")
            else:
                logger.debug(f"全局事件新事件: {event_key}")
            
            return is_duplicate
            
        except Exception as e:
            logger.debug(f"检查全局事件去重失败: {e}")
            return False  # 出错时当作新事件处理
    
    async def record_global_event(self, event_type: str, chat_id: str, user_id: int, 
                                  action: str = None, ttl: int = 5):
        """记录全局事件"""
        try:
            event_key = f"global_event:{chat_id}:{user_id}"
            if action:
                event_key += f":{hash(action) % 1000}"
            
            await self.redis.set(event_key, "1", ex=ttl)
            logger.debug(f"记录全局事件: {event_key}")
            
        except Exception as e:
            logger.debug(f"记录全局事件失败: {e}")    
