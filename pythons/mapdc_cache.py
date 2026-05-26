import asyncio
import time
import ipaddress
from telethon import TelegramClient
from telethon.sessions import MemorySession
from telethon.tl.functions.help import GetConfigRequest

GLOBAL_DC_CACHE = {
    "dc_map": None,
    "timestamp": 0
}
GLOBAL_DC_LOCK = asyncio.Lock()

async def init_global_dc_cache(api_id, api_hash, force_refresh=False):
    """初始化全局 DC 缓存，每 12 小时刷新一次"""
    async with GLOBAL_DC_LOCK:
        now = time.time()

        # 缓存有效，直接返回
        if GLOBAL_DC_CACHE["dc_map"] and (now - GLOBAL_DC_CACHE["timestamp"] < 43200) and not force_refresh:
            return GLOBAL_DC_CACHE["dc_map"]

        try:
            temp_client = TelegramClient(MemorySession(), api_id, api_hash)
            await temp_client.connect()
            config = await temp_client(GetConfigRequest())
            await temp_client.disconnect()

            dc_map = {}
            for dc in config.dc_options:
                if dc.id not in dc_map:
                    dc_map[dc.id] = {"ipv4": None, "ipv6": None}
                
                dc_info = {"ip": dc.ip_address, "port": dc.port, "ipv6": dc.ipv6}
                if dc.ipv6:
                    dc_map[dc.id]["ipv6"] = dc_info
                else:
                    dc_map[dc.id]["ipv4"] = dc_info
            
            dc_map = simplify_ipv6_in_cache(dc_map)
            
            GLOBAL_DC_CACHE["dc_map"] = dc_map
            GLOBAL_DC_CACHE["timestamp"] = now

            return dc_map

        except Exception as e:
            import traceback
            print(f"[DC缓存] 刷新失败: {e}\n{traceback.format_exc()}")
            # 返回空字典保证安全
            return GLOBAL_DC_CACHE["dc_map"] or {}
            
def simplify_ipv6_in_cache(dc_map):
    """在DC缓存中简化IPv6地址"""
    if not dc_map:
        return dc_map
    
    simplified_map = {}
    for dc_id, dc_protos in dc_map.items():
        simplified_protos = dc_protos.copy()
        
        # Check IPv6 entry
        if simplified_protos.get("ipv6"):
             ipv6_info = simplified_protos["ipv6"].copy()
             if ':' in ipv6_info.get('ip', ''):
                 try:
                    ip_obj = ipaddress.IPv6Address(ipv6_info['ip'])
                    ipv6_info['ip'] = ip_obj.compressed
                 except:
                    pass
             simplified_protos["ipv6"] = ipv6_info
             
        simplified_map[dc_id] = simplified_protos
    
    return simplified_map