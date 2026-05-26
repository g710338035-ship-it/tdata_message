# config.py
import os
from typing import Dict, Any

class Config:
    """配置类"""
    
    # MySQL配置
    MYSQL_HOST = os.getenv("MYSQL_HOST", "127.0.0.1")
    MYSQL_PORT = int(os.getenv("MYSQL_PORT", 3306))
    MYSQL_USER = os.getenv("MYSQL_USER", "tdata_tgbota_top")
    MYSQL_PASSWORD = os.getenv("MYSQL_PASSWORD", "tm5nHdJFTFEzwnkM")
    MYSQL_DATABASE = os.getenv("MYSQL_DATABASE", "tdata_tgbota_top")
    MYSQL_CHARSET = "utf8mb4"
    MYSQL_POOL_SIZE = 50
    MYSQL_MAX_OVERFLOW = 100
    MYSQL_POOL_RECYCLE=3600
    
    # Redis配置
    REDIS_HOST = os.getenv("REDIS_HOST", "127.0.0.1")
    REDIS_PORT = int(os.getenv("REDIS_PORT", 6379))
    REDIS_PASSWORD = os.getenv("REDIS_PASSWORD", "6kE3zkytdzaKeGz7")
    REDIS_DB = int(os.getenv("REDIS_DB", 10))
    REDIS_PREFIX = "tdata8"
    
    # 缓存配置
    CACHE_TTL = {
        'account_info': 300,      # 5分钟
        'groups': 300,            # 5分钟
        'contacts': 300,          # 5分钟
        'messages': 1800,          # 10分钟
        'user': 1800,             # 30分钟
        'group': 1800,            # 30分钟
        'chat_last_msg': 3600, 
        'chats':7200
    }
    
    # 监听配置
    MONITOR_SYNC_INTERVAL = 30    # 同步间隔(秒)
    MONITOR_MAX_RETRIES = 3       # 最大重试次数
    MONITOR_RETRY_DELAY = 5       # 重试延迟(秒)
    
    # 文件存储配置
    AVATAR_DIR = "../public/storage/avatars"
    MEDIA_DIR = "../public/storage/media"
    CACHE_DIR = "../public/storage/cache"
    
    @classmethod
    def get_mysql_config(cls) -> Dict[str, Any]:
        """获取MySQL配置"""
        return {
            'host': cls.MYSQL_HOST,
            'port': cls.MYSQL_PORT,
            'user': cls.MYSQL_USER,
            'password': cls.MYSQL_PASSWORD,
            'db': cls.MYSQL_DATABASE,
            'charset': cls.MYSQL_CHARSET,
            'autocommit': True,
            'minsize': 5,
            'maxsize': cls.MYSQL_POOL_SIZE,
            'pool_recycle': int(os.getenv('MYSQL_POOL_RECYCLE', 3600)),
        }
    
    @classmethod
    def get_redis_config(cls) -> Dict[str, Any]:
        """获取Redis配置"""
        return {
            'address': (cls.REDIS_HOST, cls.REDIS_PORT),
            'password': cls.REDIS_PASSWORD or None,
            'db': cls.REDIS_DB,
            'encoding': 'utf-8',
        }
    
    @classmethod
    def get_cache_key(cls, key_type: str, *args) -> str:
        """获取缓存键"""
        parts = [cls.REDIS_PREFIX, key_type]
        parts.extend(str(arg) for arg in args if arg is not None)
        return ":".join(parts)