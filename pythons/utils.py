# utils.py
import os
import logging
import asyncio
from typing import Any
from datetime import datetime, timedelta

def datetime_to_timestamp(dt):
    """将datetime对象转换为Unix时间戳"""
    if isinstance(dt, datetime):
        return int(dt.timestamp())
    return None

def parse_status(status):
    """解析Telegram用户状态对象"""
    status_data = {
        "type": status.__class__.__name__,
        "is_online": False
    }
    if hasattr(status, 'expires'):
        status_data["is_online"] = True
        status_data["expires"] = datetime_to_timestamp(status.expires)
    elif hasattr(status, 'was_online'):
        status_data["was_online"] = datetime_to_timestamp(status.was_online)
    return status_data

def setup_logging(level=logging.INFO):
    """设置日志"""
    logging.basicConfig(
        level=level,
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
        handlers=[
            logging.StreamHandler(),
            logging.FileHandler("telegram_manager.log", encoding="utf-8")
        ]
    )

def ensure_dir(directory: str):
    """确保目录存在"""
    if not os.path.exists(directory):
        os.makedirs(directory, exist_ok=True)

def format_size(size_bytes: int) -> str:
    """格式化文件大小"""
    if size_bytes == 0:
        return "0B"
    size_names = ("B", "KB", "MB", "GB", "TB")
    i = 0
    while size_bytes >= 1024 and i < len(size_names) - 1:
        size_bytes /= 1024.0
        i += 1
    return f"{size_bytes:.2f}{size_names[i]}"

def format_time(seconds: int) -> str:
    """格式化时间"""
    if seconds < 60:
        return f"{seconds}秒"
    elif seconds < 3600:
        minutes = seconds // 60
        return f"{minutes}分钟"
    elif seconds < 86400:
        hours = seconds // 3600
        return f"{hours}小时"
    else:
        days = seconds // 86400
        return f"{days}天"

def json_serializer(obj: Any) -> Any:
    """JSON序列化器"""
    if isinstance(obj, datetime):
        return obj.isoformat()
    elif isinstance(obj, timedelta):
        return str(obj)
    raise TypeError(f"Type {type(obj)} not serializable")

async def retry_async(func, max_retries: int = 3, delay: int = 1, *args, **kwargs):
    """异步重试装饰器"""
    for attempt in range(max_retries):
        try:
            return await func(*args, **kwargs)
        except Exception as e:
            if attempt == max_retries - 1:
                raise
            logging.warning(f"第 {attempt + 1} 次重试失败: {str(e)}")
            await asyncio.sleep(delay * (attempt + 1))    