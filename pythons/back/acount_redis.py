import redis
import json
import pickle
from datetime import timedelta
from typing import Optional, Any

class RedisCache:
    def __init__(self, host='localhost', port=6379, db=0, password=None, expire_seconds=3600):
        """初始化Redis连接"""
        self.redis_client = redis.Redis(
            host=host,
            port=port,
            db=db,
            password=password,
            decode_responses=False  # 支持二进制数据存储
        )
        self.expire_seconds = expire_seconds  # 默认缓存过期时间

    def set(self, key: str, value: Any, expire: Optional[int] = None) -> bool:
        """存储数据到Redis，自动序列化"""
        try:
            # 使用pickle序列化复杂对象
            serialized = pickle.dumps(value)
            expire = expire or self.expire_seconds
            return self.redis_client.set(key, serialized, ex=expire)
        except Exception as e:
            print(f"Redis set error: {str(e)}")
            return False

    def get(self, key: str) -> Optional[Any]:
        """从Redis获取数据，自动反序列化"""
        try:
            data = self.redis_client.get(key)
            if data:
                return pickle.loads(data)
            return None
        except Exception as e:
            print(f"Redis get error: {str(e)}")
            return None

    def delete(self, key: str) -> bool:
        """删除Redis中的键"""
        try:
            return self.redis_client.delete(key) > 0
        except Exception as e:
            print(f"Redis delete error: {str(e)}")
            return False

    def set_json(self, key: str, value: dict, expire: Optional[int] = None) -> bool:
        """存储JSON格式数据"""
        try:
            serialized = json.dumps(value).encode()
            expire = expire or self.expire_seconds
            return self.redis_client.set(key, serialized, ex=expire)
        except Exception as e:
            print(f"Redis set_json error: {str(e)}")
            return False

    def get_json(self, key: str) -> Optional[dict]:
        """获取JSON格式数据"""
        try:
            data = self.redis_client.get(key)
            if data:
                return json.loads(data.decode())
            return None
        except Exception as e:
            print(f"Redis get_json error: {str(e)}")
            return None

# 全局Redis缓存实例
redis_cache = RedisCache(expire_seconds=3600)  # 1小时过期