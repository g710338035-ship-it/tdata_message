import argparse
import json
import os
import asyncio
import subprocess
import hashlib
from datetime import datetime
from opentele.td import TDesktop
from opentele.api import UseCurrentSession
from opentele.exception import OpenTeleException
from telethon import TelegramClient
from telethon.sessions import MemorySession, StringSession
from telethon.tl.functions.help import GetConfigRequest
from telethon.errors import AuthKeyError as TelethonConnectionError

import phonenumbers
from phonenumbers import geocoder
import glob

# -------------------------- 核心工具函数 --------------------------
def run_tdesktop_decrypter(tdata_path, passcode=None):
    """调用tdesktop-decrypter解密tdata，提取user_id/main_dc_id/auth_key/phone"""
    try:
        cmd = ['python', '-m', 'tdesktop_decrypter', '--json', tdata_path]
        if passcode:
            cmd.insert(2, '--passcode')
            cmd.insert(3, passcode)
        
        result = subprocess.run(
            cmd, 
            capture_output=True,
            text=True,
            check=True,
            timeout=30
        )
        
        decrypted_data = json.loads(result.stdout)
        if not decrypted_data.get('accounts') or len(decrypted_data['accounts']) == 0:
            return {'error': "tdata中未找到账号数据"}
        
        account = decrypted_data['accounts'][0]
        user_id = account.get('user_id')
        main_dc_id = account.get('main_dc_id')
        dc_auth_keys = account.get('dc_auth_keys', {})
        auth_key_hex = dc_auth_keys.get(str(main_dc_id))
        
        phone = account.get('phone')
        if not phone and 'config' in account and 'phone' in account['config']:
            phone = account['config']['phone']
        
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
            'raw_data': decrypted_data
        }
    
    except subprocess.TimeoutExpired:
        return {'error': "解密命令执行超时"}
    except subprocess.CalledProcessError as e:
        return {'error': f"解密命令执行失败：{e.stderr.strip()}"}
    except json.JSONDecodeError:
        return {'error': f"解密结果解析失败：{result.stdout[:200]}..."}
    except Exception as e:
        return {'error': f"tdata解密异常：{str(e)}"}


async def get_latest_dc_config(client):
    """通过Telethon获取实时DC配置"""
    try:
        config = await asyncio.wait_for(client(GetConfigRequest()), timeout=10)
        dc_options = config.dc_options
        
        dc_config_map = {}
        for option in dc_options:
            dc_config_map[option.id] = {
                'ip': option.ip_address,
                'port': option.port,
                'hostname': getattr(option, 'hostname', '')
            }
        
        return {'success': True, 'dc_map': dc_config_map}
    
    except asyncio.TimeoutError:
        return {'error': "获取DC配置超时"}
    except Exception as e:
        return {'error': f"获取DC配置异常：{str(e)}"}


async def build_telethon_session(tdata_path, auth_key_hex, main_dc_id, user_id, api_id, api_hash, 
                               phone=None, session_type="file", session_name="telegram_session", prefer_ipv6=False):
    """构建Telethon会话（支持文件/字符串会话）"""
    try:
        if session_type == "file":
            session_file = os.path.join(tdata_path, f"temp_{user_id}.session")
            session = TelegramClient(session_file, api_id, api_hash).session
        else:
            session = MemorySession()
        
        class AuthKeyWrapper:
            def __init__(self, key_bytes):
                self.key = key_bytes
                sha1_hash = hashlib.sha1(key_bytes).digest()
                self.key_id = int.from_bytes(sha1_hash[-8:], byteorder='little', signed=False)
        
        auth_key_bytes = bytes.fromhex(auth_key_hex)
        session.auth_key = AuthKeyWrapper(auth_key_bytes)
        
        dc_full_map = None
        dc_selected_map = {}
        temp_client = None
        
        try:
            temp_session = MemorySession()
            temp_client = TelegramClient(temp_session, api_id, api_hash)
            await temp_client.connect()
            dc_config_result = await get_latest_dc_config(temp_client)
            
            if dc_config_result.get('success'):
                dc_full_map = dc_config_result['dc_map']
                ipv4_addresses = {}
                ipv6_addresses = {}
                
                for dc_id, config in dc_full_map.items():
                    if ':' not in config['ip']:
                        ipv4_addresses[dc_id] = config['ip']
                    else:
                        ipv6_addresses[dc_id] = config['ip']
                
                if prefer_ipv6 and ipv6_addresses:
                    dc_selected_map = ipv6_addresses
                elif ipv4_addresses:
                    dc_selected_map = ipv4_addresses
                else:
                    dc_selected_map = {k: v['ip'] for k, v in dc_full_map.items()}
        except Exception as e:
            print(f"[警告] 获取DC配置异常：{str(e)}")
        finally:
            if temp_client and temp_client.is_connected():
                await temp_client.disconnect()
        
        dc_id = int(main_dc_id)
        if not dc_selected_map or dc_id not in dc_selected_map:
            default_dc_map = {
                1: '149.154.175.50',
                2: '149.154.167.51',
                3: '149.154.175.100',
                4: '149.154.167.91',
                5: '149.154.171.5'
            }
            dc_selected_map = default_dc_map
        
        selected_ip = dc_selected_map.get(dc_id)
        if selected_ip:
            is_ipv6 = ':' in selected_ip
            session.set_dc(dc_id, selected_ip, 443)
        else:
            session.set_dc(dc_id, "", 443)
        
        return session
    
    except Exception as e:
        raise Exception(f"会话构建异常：{str(e)}")


async def verify_and_extract_info(session, api_id, api_hash):
    """使用TelegramClient验证会话并提取账号信息"""
    try:
        # 初始化客户端
        client = TelegramClient(
            session,
            api_id=api_id,
            api_hash=api_hash,
            request_retries=3,
            timeout=10,
            sequential_updates=True
        )
        
        # 连接验证
        if not client.is_connected():
            await asyncio.wait_for(client.connect(), timeout=15)
        
        # 验证授权状态
        is_authorized = await client.is_user_authorized()
        if not is_authorized:
            return {
                'success': False,
                'error': '会话未授权',
                'client': client
            }
        
        # 提取用户信息
        user = await client.get_me()
        if not user:
            return {
                'success': False,
                'error': '无法获取用户信息',
                'client': client
            }
        
        # 解析状态信息
        def parse_status(status):
            status_data = {"type": status.__class__.__name__, "is_online": False}
            def datetime_to_timestamp(dt):
                return int(dt.timestamp()) if dt else None

            if hasattr(status, 'expires'):
                status_data["is_online"] = True
                status_data["description"] = "在线"
                status_data["expires"] = datetime_to_timestamp(status.expires)
            elif hasattr(status, 'was_online'):
                status_data["was_online"] = datetime_to_timestamp(status.was_online)
            elif status.__class__.__name__ == "UserStatusRecently":
                status_data["description"] = "最近在线"
            elif status.__class__.__name__ == "UserStatusLastWeek":
                status_data["description"] = "上周在线"
            elif status.__class__.__name__ == "UserStatusLastMonth":
                status_data["description"] = "上月在线"
            elif status.__class__.__name__ == "UserStatusOffline":
                status_data["is_online"] = False
                status_data["description"] = "离线"
            return status_data
        
        status_info = parse_status(user.status)
        
        # 解析国家信息
        country = "未知"
        if user.phone:
            try:
                phone = user.phone if user.phone.startswith('+') else '+' + user.phone
                phone_number = phonenumbers.parse(phone, None)
                country = geocoder.country_name_for_number(phone_number, "zh")
            except:
                pass
        
        # 整理结果
        result = {
            'success': True,
            'user_id': user.id,
            'username': user.username,
            'nickname': (user.first_name or "") + (" " + user.last_name if user.last_name else ""),
            'phone': user.phone,
            'country': country,
            'status_info': status_info,
            'is_authorized': True,
            'client': client
        }
        
        return result
        
   
    except AuthKeyError:
        return {'success': False, 'error': '授权密钥无效，会话可能已过期或损坏', 'client': None}
    except asyncio.TimeoutError:
        return {'success': False, 'error': '连接超时', 'client': None}
    except Exception as e:
        return {'success': False, 'error': f'验证与提取信息失败：{str(e)}', 'client': None}


# -------------------------- 辅助函数 --------------------------
def timestamp_to_datetime(timestamp, format_str="%Y-%m-%d %H:%M:%S"):
    if not timestamp:
        return "未知时间"
    try:
        dt = datetime.fromtimestamp(timestamp)
        return dt.strftime(format_str)
    except Exception:
        return f"无效时间戳: {timestamp}"


async def main():
    parser = argparse.ArgumentParser(description='Telegram账号信息提取与会话生成工具')
    # 基础参数
    parser.add_argument('--tdata_path', required=True, help='tdata文件夹路径')
    parser.add_argument('--tdata_phone', help='tdata关联的手机号（可选）')
    parser.add_argument('--api_id', required=True, type=int, help='Telegram API ID')
    parser.add_argument('--api_hash', required=True, help='Telegram API Hash')
    # 新增参数
    parser.add_argument('--passcode', help='tdata加密密码（如有）', default=None)
    parser.add_argument('--session_type', help='会话类型（file/string）', default='file', choices=['file', 'string'])
    parser.add_argument('--session_name', help='会话名称前缀', default='telegram_session')
    parser.add_argument('--prefer_ipv6', help='优先使用IPv6地址', action='store_true')
    args = parser.parse_args()

    result = {
        'status': 0,
        'message': '',
        'auth_key': None,
        'user_id': None,
        'username': None,
        'nickname': None,
        'phone': None,
        'country': None,
        'is_authorized': False,
        'online': 0,
        'connection_status': None,
        'session_info': None
    }

    try:
        tdata_folder = args.tdata_path
        if not os.path.isdir(tdata_folder):
            result['message'] = f'tdata目录不存在: {tdata_folder}'
            print(json.dumps(result, ensure_ascii=False, indent=2))
            return

        # 清理临时会话文件
        temp_sessions = glob.glob(os.path.join(tdata_folder, "temp_*.session"))
        for f in temp_sessions:
            try:
                os.remove(f)
            except:
                pass

        session = None
        client = None
        tdesk = None
        
        # 步骤1：尝试通过TDesktop初始化会话
        try:
            #print(f"[1/3] 尝试通过TDesktop初始化会话：{tdata_folder}")
            tdesk = TDesktop(tdata_folder)
            tdesk.LoadTData()
            
            if not tdesk.accounts:
                raise OpenTeleException("tdata中未找到有效账号")
                
            #print(f"[成功] TDesktop初始化成功，找到{len(tdesk.accounts)}个账号")
            
            # 使用TDesktop生成session
            account = tdesk.accounts[0]
            
            result['user_id'] = account.UserId if account.UserId else None
            result['auth_key'] = account.authKey.key.hex() if account.authKey else None
            
            session_path = os.path.join(tdata_folder, f"temp_{account.UserId}.session")
            telethon_client = await tdesk.ToTelethon(
                session=session_path,
                flag=UseCurrentSession,
                api_id=args.api_id,
                api_hash=args.api_hash
            )
            session = telethon_client.session
            
            
        except OpenTeleException  as e:
            # TDesktop初始化失败，走解析tdata流程
            #print(f"[警告] TDesktop初始化失败: {str(e)}，尝试解析tdata生成会话")
            
            # 步骤2：解析tdata获取核心信息并生成会话
            #print(f"[2/3] 解析tdata生成会话...")
            decryption_result = run_tdesktop_decrypter(tdata_folder, args.passcode)
            if not decryption_result.get('success'):
                result['message'] = f"tdata解析失败: {decryption_result.get('error')}"
                print(json.dumps(result, ensure_ascii=False, indent=2))
                return
                
            user_id = decryption_result['user_id']
            main_dc_id = decryption_result['main_dc_id']
            auth_key_hex = decryption_result['auth_key_hex']
            phone = decryption_result.get('phone') or args.tdata_phone
            
            result['user_id'] = user_id
            result['auth_key'] = auth_key_hex
            result['phone'] = phone
            
            # 构建会话
            session = await build_telethon_session(
                tdata_path=tdata_folder,
                auth_key_hex=auth_key_hex,
                main_dc_id=main_dc_id,
                user_id=user_id,
                api_id=args.api_id,
                api_hash=args.api_hash,
                phone=phone,
                session_type=args.session_type,
                session_name=args.session_name,
                prefer_ipv6=args.prefer_ipv6
            )
            
            # 处理会话信息
            if args.session_type == "string":
                string_session = StringSession.save(session)
                result['session_info'] = {
                    'type': 'string',
                    'string': string_session,
                    'message': "StringSession生成成功"
                }
            else:
                session_path = os.path.join(tdata_folder, f"temp_{user_id}.session")
                result['session_info'] = {
                    'type': 'file',
                    'path': session_path,
                    'message': f"文件会话已保存至：{os.path.abspath(session_path)}"
                }
        result['session_path'] = session_path
        # 步骤3：统一使用TelegramClient验证并提取信息
        #print(f"[3/3] 使用TelegramClient验证会话并提取信息...")
        if not session:
            result['message'] = "会话生成失败，无法进行验证"
            print(json.dumps(result, ensure_ascii=False, indent=2))
            return
            
        # 验证会话并提取信息
        verification_result = await verify_and_extract_info(session, args.api_id, args.api_hash)
        result['connection_status'] = {
            'success': verification_result['success'],
            'message': verification_result.get('message') or verification_result.get('error')
        }
        # 手机号国家信息
        if result['phone']:
            try:
                phone = result['phone'] if result['phone'].startswith('+') else '+' + result['phone']
                phone_number = phonenumbers.parse(phone, None)
                country_info = {
                    "country_code": phone_number.country_code,
                    "national_number": phone_number.national_number,
                    "formatted": phonenumbers.format_number(phone_number, phonenumbers.PhoneNumberFormat.E164)
                }
                result['country'] = geocoder.country_name_for_number(phone_number, "zh")
            except:
                result['country'] = "unknown"
                
        if verification_result['success']:
            # 更新结果信息
            result['status'] = 1
            result['user_id'] = verification_result['user_id'] or result['user_id']
            result['username'] = verification_result['username']
            result['nickname'] = verification_result['nickname']
            result['phone'] = verification_result['phone'] or result['phone']
            result['country'] = verification_result['country'] or result['country']
            result['status_info'] = verification_result['status_info']
            result['is_authorized'] = True
            
            # 处理在线状态
            if verification_result['status_info'].get('is_online'):
                result['online'] = 1
                result['message'] = "在线"
            elif 'was_online' in verification_result['status_info']:
                last_online = timestamp_to_datetime(verification_result['status_info']['was_online'])
                result['message'] = f"最后在线时间: {last_online}"
            else:
                result['message'] = verification_result['status_info'].get('description', "未知状态")
        else:
            result['message'] = verification_result['error']
        
        # 断开连接
        if verification_result.get('client') and verification_result['client'].is_connected():
            await verification_result['client'].disconnect()
        
        print(json.dumps(result, ensure_ascii=False, indent=2))

    except Exception as e:
        result.update({
            'status': 0,
            'message': f'处理失败: {str(e)}',
            'auth_key': None,
            'is_authorized': False,
            'connection_status': {'error': f'处理过程中发生错误: {str(e)}'}
        })
        print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    asyncio.run(main())
    