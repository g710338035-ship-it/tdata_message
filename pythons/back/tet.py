import json
import os
import subprocess
import argparse
from telethon import TelegramClient
from telethon.sessions import MemorySession, StringSession
from telethon.tl.functions.help import GetConfigRequest
from datetime import datetime
import asyncio
import json
import hashlib  # 添加hashlib库用于计算SHA1哈希

# -------------------------- 核心工具函数 --------------------------
def run_tdesktop_decrypter(tdata_path, passcode=None):
    """调用tdesktop-decrypter解密tdata，提取user_id/main_dc_id/auth_key/phone"""
    try:
        # 构建解密命令
        python_exec = "/www/server/pyporject_evn/versions/3.9.23/bin/python3"
        cmd = [python_exec, '-m', 'tdesktop_decrypter', '--json', tdata_path]
        if passcode:
            cmd.insert(2, '--passcode')
            cmd.insert(3, passcode)
        
        # 执行命令并捕获输出
        result = subprocess.run(
            cmd, 
            capture_output=True,
            text=True,
            check=True,
            timeout=30  # 添加超时设置
        )
        
        # 解析解密结果
        decrypted_data = json.loads(result.stdout)
        if not decrypted_data.get('accounts') or len(decrypted_data['accounts']) == 0:
            return {'error': "tdata中未找到账号数据"}
        
        # 提取第一个账号的核心信息
        account = decrypted_data['accounts'][0]
        user_id = account.get('user_id')
        main_dc_id = account.get('main_dc_id')
        dc_auth_keys = account.get('dc_auth_keys', {})
        auth_key_hex = dc_auth_keys.get(str(main_dc_id))
        
        # 从account中提取phone（如果存在）
        phone = account.get('phone')  # 尝试从account中获取手机号
        if not phone and 'config' in account and 'phone' in account['config']:
            phone = account['config']['phone']
        
        # 校验关键信息完整性
        if not all([user_id, main_dc_id, auth_key_hex]):
            missing = []
            if not user_id: missing.append('user_id')
            if not main_dc_id: missing.append('main_dc_id')
            if not auth_key_hex: missing.append('auth_key')
            return {'error': f"解密结果缺少关键信息：{', '.join(missing)}"}
        
        return {
            'success': True,
            'user_id': user_id,
            'main_dc_id': main_dc_id,
            'auth_key_hex': auth_key_hex,
            'phone': phone,
            'raw_data': decrypted_data  # 保留原始解密结果
        }
    
    except subprocess.TimeoutExpired:
        return {'error': "解密命令执行超时，请检查网络连接"}
    except subprocess.CalledProcessError as e:
        return {'error': f"解密命令执行失败：\n错误信息：{e.stderr.strip()}\n输出日志：{e.stdout.strip()}"}
    except json.JSONDecodeError:
        return {'error': f"解密结果解析失败（非JSON格式）：{result.stdout[:200]}..."}
    except Exception as e:
        return {'error': f"tdata解密异常：{str(e)}"}


async def get_latest_dc_config(client):
    """通过Telethon的help.getConfig获取实时DC配置"""
    try:
        # 设置超时控制
        config = await asyncio.wait_for(client(GetConfigRequest()), timeout=10)
        dc_options = config.dc_options
        
        # 整理DC配置：key=dc_id，value={ip, port}
        dc_config_map = {}
        for option in dc_options:
            dc_config_map[option.id] = {
                'ip': option.ip_address,
                'port': option.port,
                'hostname': getattr(option, 'hostname', '')  # 安全获取hostname属性
            }
        
        # 打印所有DC配置
        print("\n[获取的DC配置详情]")
        print(f"共获取到 {len(dc_config_map)} 个DC配置：")
        for dc_id, dc_info in dc_config_map.items():
            print(f"DC {dc_id}: IP={dc_info['ip']}, 端口={dc_info['port']}{', 主机名=' + dc_info['hostname'] if dc_info['hostname'] else ''}")
        print("[DC配置详情结束]\n")
        
        return {'success': True, 'dc_map': dc_config_map}
    
    except asyncio.TimeoutError:
        return {'error': "获取DC配置超时"}
    except Exception as e:
        return {'error': f"获取DC配置异常：{str(e)}"}


async def build_telethon_session(tdata_path,auth_key_hex, main_dc_id,user_id, api_id, api_hash, phone=None, session_type="file", session_name="telegram_session", prefer_ipv6=False):
    """构建Telethon会话（支持文件会话或字符串会话，同时支持IPv4和IPv6）"""
    try:
        # 1. 根据会话类型初始化相应的会话对象
        if session_type == "file":
            #session_file = f"{session_name}_{main_dc_id}_{datetime.now().strftime('%Y%m%d%H%M%S')}.session"
            session_file = os.path.join(tdata_path, f"temp_{user_id}.session")
            # 直接创建文件会话对象
            session = TelegramClient(session_file, api_id, api_hash).session
        else:
            # 字符串会话使用内存会话
            session = MemorySession()
        
        # 2. 注入解密的auth_key（核心步骤）
        # 创建一个具有key和key_id属性的完整AuthKeyWrapper类
        class AuthKeyWrapper:
            def __init__(self, key_bytes):
                self.key = key_bytes
                # 计算key_id: SHA1哈希的最后8个字节（little-endian格式）
                sha1_hash = hashlib.sha1(key_bytes).digest()
                self.key_id = int.from_bytes(sha1_hash[-8:], byteorder='little', signed=False)
        
        # 将十六进制字符串转换为bytes，然后包装成AuthKeyWrapper对象
        auth_key_bytes = bytes.fromhex(auth_key_hex)
        session.auth_key = AuthKeyWrapper(auth_key_bytes)
        
        # 3. 自动获取Telegram DC的地址信息（同时支持IPv4和IPv6）
        dc_full_map = None  # 完整的DC映射，包含所有地址类型
        dc_selected_map = {}  # 最终选择的DC映射
        temp_client = None
        
        try:
            print("[正在尝试获取最新DC配置...]")
            # 创建临时客户端用于获取DC配置
            temp_session = MemorySession()
            temp_client = TelegramClient(temp_session, api_id, api_hash)
            await temp_client.connect()
            
            # 调用get_latest_dc_config获取最新DC配置
            dc_config_result = await get_latest_dc_config(temp_client)
            
            if dc_config_result.get('success'):
                dc_full_map = dc_config_result['dc_map']
                
                # 根据prefer_ipv6参数决定优先选择哪种类型的地址
                ipv4_addresses = {}
                ipv6_addresses = {}
                
                for dc_id, config in dc_full_map.items():
                    if ':' not in config['ip']:
                        ipv4_addresses[dc_id] = config['ip']
                    else:
                        ipv6_addresses[dc_id] = config['ip']
                
                print(f"[成功] 共获取到 {len(dc_full_map)} 个DC配置")
                print(f"[信息] IPv4地址数量: {len(ipv4_addresses)}, IPv6地址数量: {len(ipv6_addresses)}")
                
                # 根据用户偏好和可用地址选择合适的DC地址
                if prefer_ipv6 and ipv6_addresses:
                    print("[信息] 优先使用IPv6地址")
                    dc_selected_map = ipv6_addresses
                elif ipv4_addresses:
                    print("[信息] 优先使用IPv4地址")
                    dc_selected_map = ipv4_addresses
                else:
                    # 如果没有符合偏好的地址，使用所有可用地址
                    dc_selected_map = {k: v['ip'] for k, v in dc_full_map.items()}
            else:
                print(f"[警告] 获取DC配置失败：{dc_config_result.get('error', '未知错误')}")
        except Exception as e:
            print(f"[警告] 获取DC配置时发生异常：{str(e)}")
        finally:
            if temp_client and temp_client.is_connected():
                await temp_client.disconnect()
        
        # 如果自动获取失败或没有获取到目标DC的地址，使用硬编码的备用DC地址
        dc_id = int(main_dc_id)
        if not dc_selected_map or dc_id not in dc_selected_map:
            print("[提示] 使用备用硬编码DC地址")
            # 默认提供IPv4地址作为备用
            default_dc_map = {
                1: '149.154.175.50',
                2: '149.154.167.51',
                3: '149.154.175.100',
                4: '149.154.167.91',
                5: '149.154.171.5'
            }
            dc_selected_map = default_dc_map
        
        # 4. 设置DC信息，根据选择的地址类型设置
        selected_ip = dc_selected_map.get(dc_id)
        if selected_ip:
            is_ipv6 = ':' in selected_ip
            ip_type = "IPv6" if is_ipv6 else "IPv4"
            session.set_dc(dc_id, selected_ip, 443)
            print(f"[成功] 设置DC {dc_id} → {ip_type}地址: {selected_ip}, 端口: 443")
        else:
            # 降级方案：使用默认设置
            session.set_dc(dc_id, "", 443)
            print(f"[警告] 未找到DC {dc_id}的地址，使用Telethon默认设置")
        
        # 5. 创建正式客户端并验证连接
        client = TelegramClient(
            session,  # 使用已经配置好的session对象
            api_id=api_id,
            api_hash=api_hash,
            request_retries=3,
            timeout=10,
            sequential_updates=True
        )
        
        # 6. 尝试连接，使用asyncio超时控制
        try:
            print("[正在尝试连接Telegram服务器...]")
            await asyncio.wait_for(client.connect(), timeout=15)
            
            # 连接成功后获取账号信息
            try:
                print("[正在获取账号信息...]")
                me = await client.get_me()
                
                # 打印账号信息
                print("\n[账号信息]")
                print(f"用户ID: {me.id}")
                print(f"用户名: @{me.username}" if me.username else "用户名: 无")
                print(f"全名: {me.first_name}" + (f" {me.last_name}" if me.last_name else ""))
                print(f"电话号码: {me.phone}" if me.phone else "电话号码: 无")
                print(f"是否为Bot: {'是' if me.bot else '否'}")
                print(f"是否已验证: {'是' if me.verified else '否'}")
                print("[账号信息结束]\n")
            except Exception as e:
                print(f"[警告] 获取账号信息时出现异常：{str(e)}")
                me = None
                
        except asyncio.TimeoutError:
            return {'error': "连接超时，请检查网络连接或防火墙设置"}
        except Exception as e:
            return {'error': f"连接失败：{str(e)}，请检查网络连接"}
        
        # 7. 验证会话有效性
        try:
            if not me:
                print("[提示] 跳过在线验证，直接保存会话")
            else:
                print(f"[成功] 会话验证通过，当前账号：{me.first_name}" + (f" {me.last_name}" if me.last_name else ""))
        except Exception as e:
            print(f"[警告] 会话验证过程出现异常：{str(e)}，继续尝试保存会话")
        
        # 8. 生成目标会话类型
        if session_type == "file":
            # 文件会话会自动保存，无需额外调用save()
            await client.disconnect()
            return {
                'success': True,
                'session_type': "file",
                'session_path': session_file,
                'message': f"文件会话已保存至：{os.path.abspath(session_file)}",
                'client': client,
                'user_info': me.to_dict() if me else None  # 返回用户信息
            }
        
        elif session_type == "string":
            # 生成StringSession
            string_session = StringSession.save(client.session)
            await client.disconnect()
            return {
                'success': True,
                'session_type': "string",
                'string_session': string_session,
                'message': "StringSession生成成功（请妥善保存，切勿泄露）：",
                'client': client,
                'user_info': me.to_dict() if me else None  # 返回用户信息
            }
    
    except Exception as e:
        return {'error': f"会话构建异常：{str(e)}"}


# -------------------------- 主逻辑 --------------------------
async def main():
    # 解析命令行参数
    parser = argparse.ArgumentParser(description="tdata自动解析生成Telethon会话（支持文件/字符串会话）")
    parser.add_argument('--tdata_path', required=True, help='tdata文件夹绝对路径（如：G:\wwwroot\telegram_tdata\tdata）')
    parser.add_argument('--api_id', required=True, type=int, help='Telegram API ID（官方测试ID：2040）')
    parser.add_argument('--api_hash', required=True, help='Telegram API Hash（官方测试Hash：b18441a1ff607e10a989891a5462e627）')
    parser.add_argument('--passcode', help='tdata加密密码（如有）', default=None)
    parser.add_argument('--session_type', help='会话类型（file/string）', default='file', choices=['file', 'string'])
    parser.add_argument('--session_name', help='会话名称前缀（生成文件会话时使用）', default='telegram_session')
    parser.add_argument('--prefer_ipv6', help='优先使用IPv6地址（默认优先使用IPv4）', action='store_true')
    args = parser.parse_args()

    # 步骤1：解密tdata获取核心信息
    print(f"[1/3] 正在解密tdata：{args.tdata_path}")
    decryption_result = run_tdesktop_decrypter(args.tdata_path, args.passcode)
    if not decryption_result.get('success'):
        print(f"[失败] tdata解密失败：{decryption_result['error']}")
        return

    # 提取解密后的关键信息
    user_id = decryption_result['user_id']
    main_dc_id = decryption_result['main_dc_id']
    auth_key_hex = decryption_result['auth_key_hex']
    phone = decryption_result.get('phone')
    print(f"[成功] 解密完成 → 用户ID: {user_id}, 主DC: {main_dc_id}, AuthKey长度: {len(auth_key_hex)}")
    if phone:
        print(f"[提示] 从tdata中提取到手机号：{phone}")
    else:
        print(f"[提示] 未从tdata中提取到手机号，会话生成后可能需要手动验证")

    # 步骤2：构建并生成Telethon会话
    print(f"[2/3] 正在生成{args.session_type}类型会话...")
    session_result = await build_telethon_session(
        tdata_path=args.tdata_path,
        auth_key_hex=auth_key_hex,
        main_dc_id=main_dc_id,
        user_id=user_id,
        api_id=args.api_id,
        api_hash=args.api_hash,
        phone=phone,
        session_type=args.session_type,
        session_name=args.session_name,
        prefer_ipv6=args.prefer_ipv6  # 添加prefer_ipv6参数
    )

    # 步骤3：输出结果
    if session_result.get('success'):
        print(f"[3/3] {session_result['message']}")
        if session_result['session_type'] == 'string':
            print(f"\n[StringSession]\n{session_result['string_session']}\n")
        print("提示：使用生成的会话可直接连接Telegram，无需再次验证")
    else:
        print(f"[3/3] 会话生成失败：{session_result['error']}")


if __name__ == '__main__':
    import asyncio
    asyncio.run(main())