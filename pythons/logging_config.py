# logging_config.py
import logging
import os
from logging.handlers import RotatingFileHandler

def setup_global_logging():
    """配置全局日志"""
    
    # 创建日志目录
    log_dir = "logs"
    os.makedirs(log_dir, exist_ok=True)
    log_file = os.path.join(log_dir, "account_checker.log")
    
    # 创建格式化器
    formatter = logging.Formatter(
        "%(asctime)s [%(levelname)s] [%(name)s] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S"
    )
    
    # 控制台处理器
    console_handler = logging.StreamHandler()
    console_handler.setFormatter(formatter)
    console_handler.setLevel(logging.INFO)
    
    # 文件滚动处理器
    file_handler = RotatingFileHandler(
        log_file, 
        maxBytes=2*1024*1024,  # 10MB
        backupCount=20, 
        encoding="utf-8"
    )
    file_handler.setFormatter(formatter)
    file_handler.setLevel(logging.DEBUG)
    
    # 错误文件处理器（单独记录错误）
    error_handler = RotatingFileHandler(
        os.path.join(log_dir, "error.log"),
        maxBytes=2*1024*1024,
        backupCount=20,
        encoding="utf-8"
    )
    error_handler.setFormatter(formatter)
    error_handler.setLevel(logging.ERROR)
    
    # 获取根日志记录器
    root_logger = logging.getLogger()
    root_logger.setLevel(logging.DEBUG)
    
    # 清除现有的处理器
    root_logger.handlers.clear()
    
    # 添加处理器
    root_logger.addHandler(console_handler)
    root_logger.addHandler(file_handler)
    root_logger.addHandler(error_handler)
    
    # 设置第三方库的日志级别
    logging.getLogger("telethon").setLevel(logging.WARNING)
    logging.getLogger("aiomysql").setLevel(logging.WARNING)
    logging.getLogger("asyncio").setLevel(logging.WARNING)
    logging.getLogger("uvicorn").setLevel(logging.INFO)
    logging.getLogger("fastapi").setLevel(logging.WARNING)
    
    # 特别屏蔽 Telethon 网络层的详细日志
    telethon_loggers = [
        "telethon.network.connection.tcpfull",
        "telethon.network.mtprotosender",
        "telethon.network.mtprotorpc",
        "telethon.network.mtprotoping",
        "telethon.network",
    ]
    for logger_name in telethon_loggers:
        logging.getLogger(logger_name).setLevel(logging.ERROR)
    
    return root_logger

def get_logger(name):
    """获取已配置的日志记录器"""
    return logging.getLogger(name)