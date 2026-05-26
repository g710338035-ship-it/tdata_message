import asyncio


class ReentrantAsyncLock:
    def __init__(self):
        self._lock = asyncio.Lock()
        self._owner_task = None
        self._count = 0

    async def acquire(self):
        task = asyncio.current_task()
        if task is None:
            await self._lock.acquire()
            return True

        if self._owner_task is task:
            self._count += 1
            return True

        await self._lock.acquire()
        self._owner_task = task
        self._count = 1
        return True

    def release(self):
        task = asyncio.current_task()
        if task is None:
            self._lock.release()
            return

        if self._owner_task is not task:
            raise RuntimeError("ReentrantAsyncLock release by non-owner task")

        self._count -= 1
        if self._count <= 0:
            self._owner_task = None
            self._count = 0
            self._lock.release()

    async def __aenter__(self):
        await self.acquire()
        return self

    async def __aexit__(self, exc_type, exc, tb):
        self.release()
        return False


# --- 全局账号锁工厂 ---
# 用于确保同一进程内，对同一个 account_id 的操作使用同一把锁
# 避免多个 Handler 实例（如 batch 任务 vs 定时任务）各自创建锁，导致无法互斥
_GLOBAL_ACCOUNT_LOCKS = {}
_GLOBAL_LOCK_FACTORY_LOCK = asyncio.Lock()

def get_account_lock(lock_key: str) -> ReentrantAsyncLock:
    """
    获取全局唯一的账号锁
    lock_key: 建议格式 "account_id_type" (e.g. "123456_io", "123456_conn")
    注意：这里不用 async def 是为了方便在 __init__ 中调用，
    实际上 _GLOBAL_ACCOUNT_LOCKS 的读写在单线程 asyncio 中是安全的（除非涉及 await）
    """
    if lock_key not in _GLOBAL_ACCOUNT_LOCKS:
        _GLOBAL_ACCOUNT_LOCKS[lock_key] = ReentrantAsyncLock()
    return _GLOBAL_ACCOUNT_LOCKS[lock_key]

