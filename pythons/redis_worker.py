import sys
import os
import asyncio
import redis
import json
import logging
import threading
import time

# 将pythons目录添加到sys.path中
pythons_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '../pythons'))
if pythons_dir not in sys.path:
    sys.path.append(pythons_dir)

# 从account_checker导入必要的模块
try:
    import account_checker
    # 猴子补丁修改信号量以支持更高的并发
    # 默认值是20，增加到200以处理高负载（5000个账号）
    # 用户要求支持5000个并发登录/注销
    account_checker.TASK_SEMAPHORE = asyncio.Semaphore(200)
    
    from account_checker import process_batch_task, GLOBAL_API_ID, GLOBAL_API_HASH
except ImportError as e:
    print(f"导入account_checker时出错: {e}")
    sys.exit(1)

# 配置日志记录
logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
logger = logging.getLogger("redis_worker")

# Redis连接配置
REDIS_HOST = '127.0.0.1'
REDIS_PORT = 6379
REDIS_PASSWORD = '6kE3zkytdzaKeGz7'
REDIS_DB = 10
REDIS_QUEUE_KEY = 'telegram_python_queue'  # Redis队列键名
REDIS_RESULT_KEY = 'telegram_python_results'  # Redis结果键名

# 配置参数
CONCURRENT_WORKERS = 200  # 并发工作线程数量
INTERNAL_QUEUE_SIZE = 1000  # 内部队列缓冲区大小

async def worker_loop(queue, worker_id):
    """
    持续运行的工作循环，从内部队列消费任务
    """
    logger.info(f"[worker-{worker_id}] 工作线程已启动")
    while True:
        try:
            # 从队列获取任务
            task_item = await queue.get()
            # None是停止信号
            if task_item is None:
                queue.task_done()
                break
                
            idx, task = task_item
            try:
                # 注意：这里不需要显式获取信号量，因为process_batch_task内部会处理
                # 由于我们将信号量修改为200并且有200个工作线程，这应该完美匹配
                
                # logger.info(f"[worker-{worker_id}] 处理任务 {idx}: {task.get('action')}")
                result = await process_batch_task(
                    task_id=idx,
                    task=task,
                    api_id=GLOBAL_API_ID,
                    api_hash=GLOBAL_API_HASH
                )
                
                # 将结果推送到Redis结果队列
                if result:
                    # 如果结果中缺少meta和action，从原始任务中注入
                    if 'meta' not in result:
                        result['meta'] = task.get('meta', {})
                    if 'action' not in result:
                        result['action'] = task.get('action')
                        
                    try:
                        # 创建新连接推送结果，避免共享连接的线程安全问题
                        # 对于简单实现，我们创建新连接；或者可以使用连接池
                        # redis-py客户端是线程安全的
                        r = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DB, password=REDIS_PASSWORD)
                        r.rpush(REDIS_RESULT_KEY, json.dumps(result))
                    except Exception as e:
                        logger.error(f"[worker-{worker_id}] 推送结果到Redis失败: {e}")
                        
            except Exception as e:
                logger.error(f"[worker-{worker_id}] 任务 {idx} 处理失败: {e}")
            finally:
                queue.task_done()
                
        except Exception as e:
            logger.error(f"[worker-{worker_id}] 循环错误: {e}")
            await asyncio.sleep(1)

def redis_consumer_thread(loop, async_queue):
    """
    专用线程：从Redis列表阻塞弹出任务，并推送到asyncio队列
    """
    try:
        # 创建Redis连接（解码响应为字符串）
        r = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DB, password=REDIS_PASSWORD, decode_responses=True)
        logger.info(f"Redis消费者线程已启动，监听队列: {REDIS_QUEUE_KEY}")
    except Exception as e:
        logger.error(f"连接到Redis失败: {e}")
        return

    while True:
        try:
            # 阻塞地从Redis列表左侧弹出数据（超时5秒）
            # blpop返回 (key, value) 元组
            result = r.blpop(REDIS_QUEUE_KEY, timeout=5)
            if result:
                key, value = result
                try:
                    # 解析JSON数据
                    data = json.loads(value)
                    batch = data.get('batch')  # 获取批量任务列表
                    meta = data.get('meta', {})  # 获取元数据
                    
                    if batch and isinstance(batch, list):
                        logger.info(f"从Redis接收到批量任务: {len(batch)} 个任务。元数据: {meta}")
                        
                        # 遍历每个任务
                        for idx, task in enumerate(batch):
                            # 如果需要，将元数据注入到任务中
                            if isinstance(task, dict):
                                task['meta'] = meta
                            
                            # 安全地将任务推送到异步队列
                            future = asyncio.run_coroutine_threadsafe(async_queue.put((idx, task)), loop)
                            try:
                                # 阻塞当前线程直到任务被队列接受
                                # 这提供了背压机制：如果队列已满，此线程会在此暂停
                                future.result() 
                            except Exception as e:
                                logger.error(f"任务入队失败: {e}")

                    else:
                        logger.warning(f"从Redis接收到无效数据格式")
                except json.JSONDecodeError:
                    logger.error("从Redis解析JSON失败")
        except Exception as e:
            logger.error(f"Redis消费者错误: {e}")
            time.sleep(1)

async def main():
    """
    主异步函数：初始化队列、启动工作线程和Redis消费者
    """
    # 创建内部任务队列（带缓冲区）
    task_queue = asyncio.Queue(maxsize=INTERNAL_QUEUE_SIZE)
    
    # 启动工作线程协程
    workers = [asyncio.create_task(worker_loop(task_queue, i)) for i in range(CONCURRENT_WORKERS)]
    
    # 获取当前事件循环
    loop = asyncio.get_running_loop()
    
    # 在单独线程中启动Redis消费者
    t = threading.Thread(target=redis_consumer_thread, args=(loop, task_queue), daemon=True)
    t.start()
    
    logger.info(f"工作服务已启动，使用 {CONCURRENT_WORKERS} 个并发工作线程。按Ctrl+C退出。")
    
    try:
        # 保持主程序运行
        while True:
            await asyncio.sleep(3600)  # 每小时检查一次
    except asyncio.CancelledError:
        logger.info("正在停止工作线程...")
        # 向工作线程发送停止信号
        for _ in range(CONCURRENT_WORKERS):
            await task_queue.put(None)
        
        # 等待所有工作线程完成
        await asyncio.gather(*workers)

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        logger.info("收到键盘中断信号，程序退出。")