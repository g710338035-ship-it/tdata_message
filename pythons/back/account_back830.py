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
from telethon.errors import AuthKeyError
from telethon.errors import PeerIdInvalidError, FloodWaitError, InputUserDeactivatedError
import phonenumbers
from phonenumbers import geocoder
import glob

import stat
import pwd
import grp
import subprocess

# 导入Flask相关依赖（新增）
from flask import Flask, request, jsonify

def set_session_permissions(session_path):
    """将session文件和目录权限设置为www用户和755权限"""
    try:
        # 1. 确认文件路径
        if not os.path.exists(session_path):
            session_path_with_ext = f"{session_path}.session"
            if os.path.exists(session_path_with_ext):
                session_path = session_path_with_ext
            else:
                return False, "会话文件不存在"
        
        # 2. 获取www用户和用户组信息
        try:
            # 获取www用户的UID和GID
            www_user = pwd.getpwnam("www")
            www_uid = www_user.pw_uid
            www_gid = www_user.pw_gid
            
            # 获取www用户组名称
            www_group = grp.getgrgid(www_gid)
            www_group_name = www_group.gr_name
        except KeyError:
           
            return False, "未找到www或其他web用户（nginx/apache/www-data）"
        
        # 3. 检查并移除特殊属性（如不可修改属性）
        try:
            # 检查文件是否有不可修改属性
            if subprocess.run(
                f"lsattr {session_path} | grep -q 'i'", 
                shell=True, 
                stdout=subprocess.PIPE, 
                stderr=subprocess.PIPE
            ).returncode == 0:
                
                # 移除不可修改属性
                subprocess.run(
                    f"chattr -i {session_path}",
                    shell=True,
                    check=True
                )
        except Exception as e:
            return False, f"移除文件特殊属性失败: {str(e)}"
        
        # 4. 强制更改所有者为www用户
        try:
            # 尝试直接更改
            os.chown(session_path, www_uid, www_gid)
        except PermissionError:
            # 直接更改失败时尝试使用sudo
            try:
                subprocess.run(
                    f"sudo chown {www_uid}:{www_gid} {session_path}",
                    shell=True,
                    check=True,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE
                )
            except Exception as e:
                return False, f"更改文件所有者为www失败: {str(e)}"
        
        # 5. 强制设置文件权限为755
        try:
            os.chmod(session_path, 
                     stat.S_IRWXU |  # 用户：读、写、执行
                     stat.S_IRGRP | stat.S_IXGRP |  # 组：读、执行
                     stat.S_IROTH | stat.S_IXOTH)  # 其他：读、执行
        except PermissionError:
            try:
                subprocess.run(
                    f"sudo chmod 755 {session_path}",
                    shell=True,
                    check=True,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE
                )
            except Exception as e:
                return False, f"设置文件权限为755失败: {str(e)}"
        
        # 6. 处理目录权限
        session_dir = os.path.dirname(session_path)
        if os.path.exists(session_dir):
            # 移除目录特殊属性
            try:
                if subprocess.run(
                    f"lsattr {session_dir} | grep -q 'i'", 
                    shell=True, 
                    stdout=subprocess.PIPE, 
                    stderr=subprocess.PIPE
                ).returncode == 0:
                    
                    subprocess.run(
                        f"chattr -i {session_dir}",
                        shell=True,
                        check=True
                    )
            except Exception as e:
                return False, f"移除目录特殊属性失败: {str(e)}"
            
            # 更改目录所有者为www
            try:
                os.chown(session_dir, www_uid, www_gid)
            except PermissionError:
                try:
                    subprocess.run(
                        f"sudo chown {www_uid}:{www_gid} {session_dir}",
                        shell=True,
                        check=True,
                        stdout=subprocess.PIPE,
                        stderr=subprocess.PIPE
                    )
                except Exception as e:
                    return False, f"更改目录所有者为www失败: {str(e)}"
            
            # 设置目录权限为755
            try:
                os.chmod(session_dir, 
                         stat.S_IRWXU |  # 用户：读、写、执行
                         stat.S_IRGRP | stat.S_IXGRP |  # 组：读、执行
                         stat.S_IROTH | stat.S_IXOTH)  # 其他：读、执行
            except PermissionError:
                try:
                    subprocess.run(
                        f"sudo chmod 755 {session_dir}",
                        shell=True,
                        check=True,
                        stdout=subprocess.PIPE,
                        stderr=subprocess.PIPE
                    )
                except Exception as e:
                    return False, f"设置目录权限为755失败: {str(e)}"
        
        # 7. 验证最终权限
        file_stat = os.stat(session_path)
        file_perms = oct(stat.S_IMODE(file_stat.st_mode))
        file_owner = pwd.getpwuid(file_stat.st_uid).pw_name
        file_group = grp.getgrgid(file_stat.st_gid).gr_name
        
        dir_perms = "N/A"
        dir_owner = "N/A"
        dir_group = "N/A"
        if os.path.exists(session_dir):
            dir_stat = os.stat(session_dir)
            dir_perms = oct(stat.S_IMODE(dir_stat.st_mode))
            dir_owner = pwd.getpwuid(dir_stat.st_uid).pw_name
            dir_group = grp.getgrgid(dir_stat.st_gid).gr_name
        
        return True, (f"权限设置成功 - "
                     f"文件: 权限={file_perms}, 所有者={file_owner}:{file_group}; "
                     f"目录: 权限={dir_perms}, 所有者={dir_owner}:{dir_group}")
                     
    except Exception as e:
        return False, f"权限设置过程发生错误: {str(e)}"

# -------------------------- 核心工具函数 --------------------------
def run_tdesktop_decrypter(tdata_path, passcode=None):
    """调用tdesktop-decrypter解密tdata，提取user_id/main_dc_id/auth_key/phone"""
    try:
        python_exec = "/www/server/pyporject_evn/versions/3.9.23/bin/python3"
        cmd = [python_exec, '-m', 'tdesktop_decrypter', '--json', tdata_path]
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


async def build_telethon_session(tdata_path, auth_key_hex, main_dc_id, user_id, api_id, api_hash, phone=None, session_type="file", session_name="telegram_session", prefer_ipv6=False):
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
                'account_status': '未授权',
                'account_status_desc': '账号未授权或会话已失效',
                'client': client
            }
        
        # 提取用户信息
        user = await client.get_me()
        if not user:
            return {
                'success': False,
                'error': '无法获取用户信息',
                'account_status': '异常',
                'account_status_desc': '无法获取用户信息，可能是网络问题或会话无效',
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
        # 检查账号状态（是否被封禁）
        account_status = '正常'
        account_status_desc = '账号状态正常'
        # 检查用户对象是否有注销相关标志
        if hasattr(user, 'deleted') and user.deleted:
            account_status = '注销'
            account_status_desc = '账号已被用户主动注销'
        elif hasattr(user, 'restricted') and user.restricted:
            account_status = '限制'
            account_status_desc = '账号功能已被限制'
        else:
        # 尝试发送一个简单的请求来测试账号是否被封禁
            try:
                # 获取自己的对话列表，这是一个简单的操作但需要账号有效
                await client.get_dialogs(limit=1)
            except Exception as e:
                # 如果遇到特定错误，判断为账号异常或被封禁
                error_str = str(e)
                if 'AUTH_KEY_UNREGISTERED' in error_str or 'SESSION_PASSWORD_NEEDED' in error_str:
                    account_status = '异常'
                    account_status_desc = f'账号异常: {error_str}'
                elif 'USER_DEACTIVATED' in error_str or 'USER_BANNED' in error_str:
                    if 'USER_DEACTIVATED' in error_str:
                        account_status = '注销'
                        account_status_desc = f'账号已被注销: {error_str}'
                    else:
                        account_status = '封号'
                        account_status_desc = f'账号已被封禁: {error_str}'
                elif any(code in error_str for code in ['USER_DEACTIVATED_BAN', 'USER_DELETED', 'ACCOUNT_DELETED']):
                    account_status = '注销'
                    account_status_desc = f'账号已被注销: {error_str}'
                else:
                    account_status = '异常'
                    account_status_desc = f'账号状态异常: {error_str}'
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
            'account_status': account_status,
            'account_status_desc': account_status_desc,
            'client': client
        }
        
        return result
        
   
    except AuthKeyError:
        return {'success': False, 'error': '授权密钥无效，会话可能已过期或损坏', 'account_status': '异常', 'account_status_desc': '授权密钥无效', 'client': None}
    except asyncio.TimeoutError:
        return {'success': False, 'error': '连接超时', 'account_status': '异常', 'account_status_desc': '连接超时，可能是网络问题', 'client': None}
    except Exception as e:
        error_str = str(e)
        account_status = '异常'
        account_status_desc = f'验证过程异常: {error_str}'
        if 'USER_DEACTIVATED' in error_str:
            account_status = '注销'
            account_status_desc = f'账号已被注销: {error_str}'
        elif 'USER_BANNED' in error_str:
            account_status = '封号'
            account_status_desc = f'账号已被封禁: {error_str}'
        elif any(code in error_str for code in ['USER_DEACTIVATED_BAN', 'USER_DELETED', 'ACCOUNT_DELETED']):
            account_status = '注销'
            account_status_desc = f'账号已被注销: {error_str}'
        return {'success': False, 'error': f'验证与提取信息失败：{error_str}', 'account_status': account_status, 'account_status_desc': account_status_desc, 'client': None}
    finally:
        # 确保无论如何都会断开连接
        if client and client.is_connected():
            try:
                await client.disconnect()
            except:
                pass

# -------------------------- 辅助函数 --------------------------
def timestamp_to_datetime(timestamp, format_str="%Y-%m-%d %H:%M:%S"):
    if not timestamp:
        return "未知时间"
    try:
        dt = datetime.fromtimestamp(timestamp)
        return dt.strftime(format_str)
    except Exception:
        return f"无效时间戳: {timestamp}"

# -------------------------- 新增：Flask HTTP接口逻辑 --------------------------
app = Flask(__name__)

# （可选）接口认证：防止非法调用，建议设置自定义密钥
#AUTH_KEY = "your_secure_api_key_2024"  # 替换为自己的安全密钥

@app.route("/health", methods=["GET"])
def health_check():
    """服务健康检查接口（用于宝塔/监控工具检测服务状态）"""
    return jsonify({"status": 1, "message": "Python服务运行正常"}), 200

@app.route("/check_account", methods=["POST"])
def check_account():
    """核心接口：接收PHP请求，处理账号检查（整合原始原始main函数完整逻辑）"""
    # 初始化返回结果格式（与原脚本输出一致）
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
        'connection_status': {'success': False, 'message': ''},
        'session_info': {'type': '', 'path': '', 'message': ''},
        'session_path': None,  # 新增：保留会话路径
        'status_info': None,   # 新增：状态详情
        'account_status': None,
        'account_status_desc': None
    }

    try:
        # 1. 获取并验证请求参数
        data = request.json
        if not data:
            result['message'] = '请求参数为空，请使用JSON格式'
            return jsonify(result), 400

        # 验证必要参数
        required_fields = ['tdata_path', 'api_id', 'api_hash']
        for field in required_fields:
            if field not in data or not data[field]:
                result['message'] = f'缺少必要参数: {field}'
                return jsonify(result), 400

        # 解析参数（与原始main函数参数保持一致）
        args = {
            'tdata_path': data['tdata_path'],
            'tdata_phone': data.get('tdata_phone', ''),
            'api_id': int(data['api_id']),
            'api_hash': data['api_hash'],
            'passcode': data.get('passcode'),
            'session_type': data.get('session_type', 'file'),
            'session_name': data.get('session_name', 'telegram_session'),
            'prefer_ipv6': data.get('prefer_ipv6', False)
        }

        # 2. 验证tdata目录是否存在
        tdata_folder = args['tdata_path']
        if not os.path.isdir(tdata_folder):
            result['message'] = f'tdata目录不存在: {tdata_folder}'
            result['connection_status']['message'] = result['message']
            return jsonify(result)

        # 3. 清理临时会话文件（与原始逻辑一致）
        temp_sessions = glob.glob(os.path.join(tdata_folder, "temp_*.session"))
        for f in temp_sessions:
            try:
                os.remove(f)
            except Exception as e:
                result['message'] += f"（清理临时文件警告: {str(e)}）"

        # 初始化变量（与原始main函数一致）
        session = None
        client = None
        tdesk = None
        session_path = None

        try:
            # 步骤1：尝试通过TDesktop初始化会话（原始main函数核心逻辑）
            tdesk = TDesktop(tdata_folder)
            tdesk.LoadTData()
            
            if not tdesk.accounts:
                raise OpenTeleException("tdata中未找到有效账号")
            
            # 使用TDesktop生成session
            account = tdesk.accounts[0]
            result['user_id'] = account.UserId if account.UserId else None
            result['auth_key'] = account.authKey.key.hex() if account.authKey else None
            
            # 生成会话路径
            session_path = os.path.join(tdata_folder, f"temp_{account.UserId}.session")
            telethon_client = await tdesk.ToTelethon(
                session=session_path,
                flag=UseCurrentSession,
                api_id=args['api_id'],
                api_hash=args['api_hash']
            )
            session = telethon_client.session
            # 设置会话权限
            perm_result, perm_msg = set_session_permissions(session_path)
            if not perm_result:
                result['session_info']['message'] = f"权限设置警告: {perm_msg}"
            
            # 更新会话信息
            result['session_info'] = {
                'type': 'file',
                'path': session_path,
                'message': f"通过TDesktop生成会话: {os.path.abspath(session_path)}"
            }
            result['session_path'] = session_path

        except OpenTeleException as e:
            # TDesktop初始化失败，走解析tdata流程（原始逻辑分支）
            result['message'] += f"（TDesktop初始化失败: {str(e)}，尝试解析tdata）"
            
            # 步骤2：解析tdata获取核心信息并生成会话
            decryption_result = run_tdesktop_decrypter(tdata_folder, args['passcode'])
            if not decryption_result.get('success'):
                result['message'] = f"tdata解析失败: {decryption_result.get('error')}"
                result['connection_status']['message'] = result['message']
                return jsonify(result)
                
            # 提取解密信息
            user_id = decryption_result['user_id']
            main_dc_id = decryption_result['main_dc_id']
            auth_key_hex = decryption_result['auth_key_hex']
            phone = decryption_result.get('phone') or args['tdata_phone']
            
            result['user_id'] = user_id
            result['auth_key'] = auth_key_hex
            result['phone'] = phone
            
            # 构建会话（与原始逻辑一致）
            session = await build_telethon_session(
                tdata_path=tdata_folder,
                auth_key_hex=auth_key_hex,
                main_dc_id=main_dc_id,
                user_id=user_id,
                api_id=args['api_id'],
                api_hash=args['api_hash'],
                phone=phone,
                session_type=args['session_type'],
                session_name=args['session_name'],
                prefer_ipv6=args['prefer_ipv6']
            )
            
            # 处理会话信息（区分文件/字符串会话）
            if args['session_type'] == "string":
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
                # 设置会话权限
                set_session_permissions(session_path)
            
            result['session_path'] = session_path

        # 步骤3：统一验证会话并提取信息（原始main函数逻辑）
        if not session:
            result['message'] = "会话生成失败，无法进行验证"
            result['connection_status']['message'] = result['message']
            return jsonify(result)
            
        # 验证会话并提取信息
        verification_result = await verify_and_extract_info(session, args['api_id'], args['api_hash'])
        result['connection_status'] = {
            'success': verification_result['success'],
            'message': verification_result.get('message') or verification_result.get('error', '')
        }

        # 处理手机号国家信息（原始逻辑）
        if result['phone']:
            try:
                phone = result['phone'] if result['phone'].startswith('+') else '+' + result['phone']
                phone_number = phonenumbers.parse(phone, None)
                result['country'] = geocoder.country_name_for_number(phone_number, "zh")
            except:
                result['country'] = "unknown"

        # 处理验证成功的情况
        if verification_result['success']:
            result['status'] = 1
            result['user_id'] = verification_result['user_id'] or result['user_id']
            result['username'] = verification_result['username']
            result['nickname'] = verification_result['nickname']
            result['phone'] = verification_result['phone'] or result['phone']
            result['country'] = verification_result['country'] or result['country']
            result['status_info'] = verification_result['status_info']
            result['is_authorized'] = True
            result['account_status'] = verification_result.get('account_status')
            result['account_status_desc'] = verification_result.get('account_status_desc')
            
            # 处理在线状态（原始逻辑）
            if verification_result['status_info'].get('is_online'):
                result['online'] = 1
                result['message'] = "在线"
            elif 'was_online' in verification_result['status_info']:
                last_online = timestamp_to_datetime(verification_result['status_info']['was_online'])
                result['message'] = f"最后在线时间: {last_online}"
            else:
                result['message'] = verification_result['status_info'].get('description', "未知状态")
            
            # 处理账号异常状态
            if result['account_status'] == '异常':
                result['message'] = f"{result['account_status_desc']}"
            elif result['account_status'] == '封号':
                result['message'] = f"{result['account_status_desc']}"
        
        # 处理验证失败的情况
        else:
            result['message'] = verification_result['error']
            if 'account_status' in verification_result:
                result['account_status'] = verification_result['account_status']
                result['account_status_desc'] = verification_result['account_status_desc']

        # 确保客户端断开连接
        if verification_result.get('client') and verification_result['client'].is_connected():
            await verification_result['client'].disconnect()

        return jsonify(result)

    except Exception as e:
        # 捕获所有未处理的异常
        error_msg = f'处理失败: {str(e)}'
        result.update({
            'status': 0,
            'message': error_msg,
            'auth_key': None,
            'is_authorized': False,
            'connection_status': {'success': False, 'error': error_msg}
        })
        return jsonify(result), 500


if __name__ == '__main__':
    # 生产环境建议使用Gunicorn启动
    # 启动命令示例: gunicorn -w 4 -b 0.0.0.0:5000 account_checker:app
    import sys
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 5000
    app.run(
        host='0.0.0.0',
        port=port,
        debug=False,  # 生产环境必须设为False
        threaded=True  # 启用多线程处理请求
    )
    