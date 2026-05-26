import asyncio
import random
import time
from typing import Any, Awaitable, Callable, Dict, Optional

#Telegram API governor
# 用于管理Telegram API调用的速率限制和缓存
class TelegramApiGovernor:
    def __init__(
        self,
        min_interval: float = 0.8,
        entity_ttl: int = 300,
        me_ttl: int = 60,
        rate_lock: Optional[asyncio.Lock] = None,
    ):
        self.min_interval = float(min_interval)
        self.entity_ttl = int(entity_ttl)
        self.me_ttl = int(me_ttl)
        self._api_call_at: Dict[str, float] = {}
        self._entity_cache: Dict[str, Dict[str, Any]] = {}
        self._me_cache: Dict[str, Any] = {"value": None, "timestamp": 0.0}
        self._rate_lock = rate_lock or asyncio.Lock()

    async def wait_for_slot(self, action: str = "default", min_interval: Optional[float] = None):
        interval = self.min_interval if min_interval is None else float(min_interval)
        if interval <= 0:
            return
        async with self._rate_lock:
            now = time.time()
            last = self._api_call_at.get(action, 0.0)
            wait_seconds = interval - (now - last)
            if wait_seconds > 0:
                await asyncio.sleep(wait_seconds + random.uniform(0.03, 0.12))
            self._api_call_at[action] = time.time()

    async def get_me(
        self,
        fetcher: Callable[[], Awaitable[Any]],
        force_refresh: bool = False,
        ttl: Optional[int] = None,
        action: str = "get_me",
        min_interval: Optional[float] = 0.3,
    ):
        now = time.time()
        max_ttl = self.me_ttl if ttl is None else int(ttl)
        cached_user = self._me_cache.get("value")
        cached_ts = float(self._me_cache.get("timestamp", 0.0) or 0.0)
        if (not force_refresh) and cached_user is not None and (now - cached_ts < max_ttl):
            return cached_user
        await self.wait_for_slot(action=action, min_interval=min_interval)
        user = await fetcher()
        self._me_cache["value"] = user
        self._me_cache["timestamp"] = time.time()
        return user

    async def get_entity(
        self,
        entity_ref: Any,
        fetcher: Callable[[Any], Awaitable[Any]],
        force_refresh: bool = False,
        ttl: Optional[int] = None,
        action: str = "get_entity",
        min_interval: Optional[float] = 0.35,
    ):
        cache_key = str(entity_ref)
        now = time.time()
        max_ttl = self.entity_ttl if ttl is None else int(ttl)
        cache_entry = self._entity_cache.get(cache_key)
        if (
            (not force_refresh)
            and cache_entry is not None
            and (now - float(cache_entry.get("timestamp", 0.0)) < max_ttl)
        ):
            return cache_entry.get("value")
        await self.wait_for_slot(action=action, min_interval=min_interval)
        entity = await fetcher(entity_ref)
        self._entity_cache[cache_key] = {"value": entity, "timestamp": time.time()}
        return entity

    def clear(self):
        self._entity_cache.clear()
        self._me_cache = {"value": None, "timestamp": 0.0}
