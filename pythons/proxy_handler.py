from telethon import TelegramClient
# 移除错误的ProxyError导入
# from telethon.errors import ProxyError  # 删除这行
import socks
import time
from telethon.sessions import StringSession

def parse_proxytul(proxy_str):
    """
    解析代理字符串为 Telethon 可用格式 (tuple)
    支持格式：
        protocol://username:password@ip:port
        protocol://ip:port
    """
    if not proxy_str:
        return None
        
    try:
        proxy_str = proxy_str.strip()
        protocols = {
            "socks5": socks.SOCKS5,
            "socks5h": socks.SOCKS5,
            "socks4": socks.SOCKS4,
            "http": socks.HTTP
        }
        protocol = socks.SOCKS5  # 默认 socks5
        rdns = True
        # 提取协议
        for p in protocols:
            if proxy_str.startswith(f"{p}://"):
                protocol = protocols[p]
                rdns = True if p.endswith('h') else True
                proxy_str = proxy_str[len(f"{p}://"):]
                proxy_str = proxy_str.lstrip('/')  # 删除多余的斜杠
                break

        # 分割认证信息和地址
        if '@' in proxy_str:
            auth_part, addr_part = proxy_str.split('@', 1)
            username, password = auth_part.split(':', 1) if ':' in auth_part else (auth_part, '')
        else:
            addr_part = proxy_str
            username, password = '', ''
            
        # 分割IP和端口
        ip, port = addr_part.split(':', 1)
        port = int(port)
        
        # ✅ Telethon 需要 tuple 格式，而不是 dict
        # 格式为: (proxy_type, host, port, rdns, username, password)
        #return (protocol, ip, port, True, username, password)
        return (protocol, ip, port, rdns, username, password)
        
    except Exception as e:
        raise ValueError(f"代理格式错误: {str(e)}，正确格式：protocol://username:password@ip:port")
        
def parse_proxy(proxy_str):
    """
    解析代理字符串为字典
    支持格式：protocol://username:password@ip:port 或 protocol://ip:port
    """
    if not proxy_str:
        return None
        
    try:
        protocols = ['http', 'socks4', 'socks5', 'socks5h']
        protocol = 'socks5'
        rdns = True
        
        # 提取协议
        for p in protocols:
            if proxy_str.startswith(f"{p}://"):
                protocol = p
                rdns = True if p.endswith('h') else True
                proxy_str = proxy_str[len(f"{p}://"):]
                proxy_str = proxy_str.lstrip('/')  # 删除可能多余的斜杠
                break

    
       
        # 分割认证信息和地址
        if '@' in proxy_str:
            auth_part, addr_part = proxy_str.split('@', 1)
            username, password = auth_part.split(':', 1) if ':' in auth_part else (auth_part, '')
        else:
            addr_part = proxy_str
            username, password = '', ''
            
        # 分割IP和端口
        ip, port = addr_part.split(':', 1)
        port = int(port)
        
        return {
            "protocol": protocol,
            "ip": ip,
            "port": port,
            "username": username,
            "password": password,
            "rdns": rdns
        }
    except Exception as e:
        raise ValueError(f"代理格式错误: {str(e)}，正确格式：protocol://username:password@ip:port")

async def test_proxy(proxy_info, api_id, api_hash):
    """测试代理有效性"""
    try:
        proto_map = {
            'socks5': socks.SOCKS5,
            'socks5h': socks.SOCKS5,
            'socks4': socks.SOCKS4,
            'http': socks.HTTP
        }
        proxy_type = proto_map.get(proxy_info.get('protocol', 'socks5'), socks.SOCKS5)
        rdns = proxy_info.get('rdns', True if str(proxy_info.get('protocol','')).endswith('h') else True)
        proxy_params = (
            proxy_type,
            proxy_info['ip'],
            proxy_info['port'],
            rdns,
            proxy_info['username'],
            proxy_info['password']
        ) if proxy_info.get('username') else (
            proxy_type,
            proxy_info['ip'],
            proxy_info['port']
        )
        start = time.time()
       
        # 测试连接
        client = TelegramClient(
            session=StringSession(),
            api_id=api_id,
            api_hash=api_hash,
            proxy=proxy_params,
            connection_retries=1,
            timeout=5
        )
        step1_end = time.time()
        await client.connect()
        is_connected =  client.is_connected()
        await client.disconnect()
        end = time.time()
        return {
            "status": True,
            "message": f"获取基本信息耗时: {step1_end - start}, 处理数据耗时: {end - step1_end}",
            "data": proxy_info
        }

    except Exception as e:
        return {
            "status": False,
            "message": f"代理测试失败: {str(e)}",
            "data": proxy_info
        }
    
    