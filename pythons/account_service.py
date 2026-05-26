import os
import asyncio
import subprocess
import hashlib
import stat
import pwd
import grp
import json
from opentele2.td import TDesktop
from opentele2.api import API, UseCurrentSession, CreateNewSession
from opentele2.exception import OpenTeleException
from telethon import TelegramClient
from telethon.tl.functions.users import GetFullUserRequest
from telethon.errors import FloodWaitError,AuthKeyError
from telethon.network.connection.tcpobfuscated import ConnectionTcpObfuscated
import phonenumbers
from phonenumbers import geocoder

from logging_config import get_logger
logger = get_logger(__name__)

import random
import time

from mapdc_cache import init_global_dc_cache


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
            www_user = pwd.getpwnam("www")
            www_uid = www_user.pw_uid
            www_gid = www_user.pw_gid
            
            www_group = grp.getgrgid(www_gid)
           
        except KeyError:
            return False, "未找到www或其他web用户（nginx/apache/www-data）"
        
        # 3. 检查并移除特殊属性
        try:
            if subprocess.run(
                f"lsattr {session_path} | grep -q 'i'", 
                shell=True, 
                stdout=subprocess.PIPE, 
                stderr=subprocess.PIPE
            ).returncode == 0:
                
                subprocess.run(
                    f"chattr -i {session_path}",
                    shell=True,
                    check=True
                )
        except Exception as e:
            return False, f"移除文件特殊属性失败: {str(e)}"
        
        # 4. 强制更改所有者为www用户
        try:
            os.chown(session_path, www_uid, www_gid)
        except PermissionError:
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
"""调用tdesktop-decrypter解密tdata，优化进程调用"""
def run_tdesktop_decrypter(tdata_path, passcode=None):
    
    try:
        python_exec = "/www/server/pyporject_evn/versions/3.11.14/bin/python3"
        #python_exec = "/www/server/pyporject_evn/versions/3.14.2/bin/python3"
        cmd = [python_exec, '-m', 'tdesktop_decrypter', '--json', tdata_path]
        if passcode:
            cmd.insert(2, '--passcode')
            cmd.insert(3, passcode)
        
        # 优化：使用Popen并限制缓冲区大小
        proc = subprocess.Popen(
            cmd, 
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            bufsize=4096  # 4KB缓冲区
        )
        
        stdout, stderr = proc.communicate(timeout=20)  # 缩短超时时间
        
        if proc.returncode != 0:
            return {'error': f"解密命令执行失败：{stderr.strip()[:500]}"}
        
        # 快速验证JSON格式
        if not stdout.strip().startswith('{'):
            return {'error': f"解密结果格式错误：{stdout[:200]}..."}
            
        decrypted_data = json.loads(stdout)
        
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
    except json.JSONDecodeError:
        return {'error': f"解密结果解析失败：{stdout[:200]}..."}
    except Exception as e:
        return {'error': f"tdata解密异常：{str(e)}"}


"""构建并验证Telethon会话（支持文件/字符串会话），并提取账号信息"""

async def build_telethon_session(tdata_path, auth_key_hex, main_dc_id, user_id, api_id, api_hash, phone=None,proxy=None, session_type="file", session_name="telegram_session", prefer_ipv6=False, force_dc_refresh=False):
    
    try:
        client = None  # 初始化client变量
        session_file = None  # 初始化会话文件路径变量
        connection_kept = False
         
        # 构建会话部分
        if session_type == "file":
            from session_utils import SafeSQLiteSession, ensure_writable_session_file, resolve_session_file, safe_remove_session_file

            session_file = resolve_session_file(tdata_path, user_id)
            session_file = ensure_writable_session_file(session_file, user_id=user_id)
            if session_file and os.path.exists(session_file):
                if not safe_remove_session_file(session_file):
                    logger.warning(f"清理旧session文件失败：{session_file}")
            session_file = ensure_writable_session_file(session_file, user_id=user_id)
            client = TelegramClient(
                SafeSQLiteSession(session_file),
                api_id, 
                api_hash,
                use_ipv6=True
            )
            session = client.session
   
        
        class AuthKeyWrapper:
            def __init__(self, key_bytes):
                self.key = key_bytes
                sha1_hash = hashlib.sha1(key_bytes).digest()
                self.key_id = int.from_bytes(sha1_hash[-8:], byteorder='little', signed=False)
        
        auth_key_bytes = bytes.fromhex(auth_key_hex)
        session.auth_key = AuthKeyWrapper(auth_key_bytes)
        
        dc_full_map = None        
        now = time.time()
        dc_full_map = await init_global_dc_cache(api_id, api_hash, force_refresh=force_dc_refresh)
        #logger.info(f"[dc_full_map缓存] 当前 dc_map: {dc_full_map}")

        dc_id = int(main_dc_id)
        
        dc_options = dc_full_map.get(dc_id, {})
        
        selected_ip = "91.108.56.130"
        selected_port = 443
        
        if prefer_ipv6 and dc_options.get("ipv6"):
             selected_ip = dc_options["ipv6"]["ip"]
             selected_port = dc_options["ipv6"]["port"]
             logger.info(f"[会话构建] 使用 IPv6: {selected_ip}:{selected_port}")
        elif dc_options.get("ipv4"):
             selected_ip = dc_options["ipv4"]["ip"]
             selected_port = dc_options["ipv4"]["port"]
             logger.info(f"[会话构建] 使用 IPv4: {selected_ip}:{selected_port}")
        elif dc_options.get("ipv6"):
             selected_ip = dc_options["ipv6"]["ip"]
             selected_port = dc_options["ipv6"]["port"]
             logger.info(f"[会话构建] 仅有 IPv6 可用: {selected_ip}:{selected_port}")
        else:
             #logger.warning(f"[会话构建] 未找到 DC {dc_id} 的 IP 信息，使用默认值: {selected_ip}")
             pass

        session.set_dc(dc_id, selected_ip, selected_port)
        
        if session_type == "file":
            try:
                session.save()
                logger.info(f"[会话构建] session保存成功")
            except Exception as e:
                logger.warning(f"[会话构建] session保存失败：{e}")
        
        # 断开第一次的client连接（如果连接了）
        if client and client.is_connected():
            try:
                await client.disconnect()
            except:
                pass
            
        # 验证会话并提取信息部分
        try:
            # 初始化客户端
            client = TelegramClient(
                session,  # 这里传递的是已经配置好的session对象
                api_id=api_id,
                api_hash=api_hash,
                request_retries=1,  # 增加重试次数
                timeout=15,  # 增加超时时间
                connection_retries=1,  # 连接重试次数
                retry_delay=2,  # 重试延迟
                auto_reconnect=False,  # 启用自动重连
                sequential_updates=True,
                proxy=proxy,
                use_ipv6=True
               
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
                    #'session': session,
                    'session_file': session_file
                }
            
            # 提取用户信息
            user = await asyncio.wait_for(client.get_me(), timeout=5)
            if not user:
                return {
                    'success': False,
                    'error': '无法获取用户信息',
                    'account_status': '异常',
                    'account_status_desc': '无法获取用户信息，可能是网络问题或会话无效',
                    #'session': session,
                    'session_file': session_file
                }
                
            full_user = await asyncio.wait_for(client(GetFullUserRequest(user.id)), timeout=5)
            logger.info(f"检测到bot_verification:full_user={full_user}")
            # 提取bot_verification信息
            if (hasattr(full_user, 'full_user') and 
                hasattr(full_user.full_user, 'bot_verification') and 
                full_user.full_user.bot_verification):
                
                bot_ver = full_user.full_user.bot_verification
                bot_id = getattr(bot_ver, 'bot_id', 'unknown')
                description = getattr(bot_ver, 'description', '')
                icon = getattr(bot_ver, 'icon', None)
                
                logger.info(f"检测到bot_verification:user_id={user_id} bot_id={bot_id}, description='{description}'")
                
                if description and 'frozen' in description.lower():
                    return {
                        'success': False,
                        'error': '账号被Telegram官方冻结',
                        'account_status': '冻结',
                        'account_status_desc': '账号被Telegram官方冻结',
                        #'session': session,
                        'session_file': session_file
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
            
            # 检查账号状态
            account_status = '正常'
            account_status_desc = '账号状态正常'
            if hasattr(user, 'deleted') and user.deleted:
                account_status = '注销'
                account_status_desc = '账号已被用户主动注销'
            elif hasattr(user, 'restricted') and user.restricted:
                account_status = '冻结'
                account_status_desc = '账号被冻结'
                  
            if(account_status=='正常'):            
                connection_kept = True
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
                #'session': session,
                'session_file': session_file,
                'client':client
            }
            
            return result
            
       
        except AuthKeyError:
            return {
                'success': False, 
                'error': '授权密钥无效，会话可能已过期或损坏', 
                'account_status': '异常', 
                'account_status_desc': '授权密钥无效', 
                #'session': session,
                'session_file': session_file,
                'client': None
            }
        except asyncio.TimeoutError:
            return {
                'success': False, 
                'error': '连接超时', 
                'account_status': '异常', 
                'account_status_desc': '连接超时', 
                #'session': session,
                'session_file': session_file,
                'client': None
            }
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
            return {
                'success': False, 
                'error': f'验证与提取信息失败：{error_str}', 
                'account_status': account_status, 
                'account_status_desc': account_status_desc,
                #'session': session,
                'session_file': session_file,
                'client': None
            }
        
        finally:
            # 确保断开连接
            if not connection_kept and 'client' in locals() and client and client.is_connected():
                try:
                    await client.disconnect()
                except:
                    pass
      
    except Exception as e:
        return {
            'success': False,
            'error': f'会话构建异常：{str(e)}',
            'session': None,
            'session_file': None,
            'client': None
        }
        

async def build_telethon_session_then(tdata_path, auth_key_hex, main_dc_id, user_id, api_id, api_hash, phone=None,proxy=None, session_type="file", session_name="telegram_session", prefer_ipv6=False, force_dc_refresh=False):
    
    client = None
    session_file = None
    connection_kept = False
    text_info=''
    try:
        tdesk = TDesktop(tdata_path)
        assert tdesk.isLoaded()

        from session_utils import ensure_writable_session_file, resolve_session_file

        session_file = resolve_session_file(tdata_path, user_id)
        session_file = ensure_writable_session_file(session_file, user_id=user_id)

        if not tdesk.accounts:
            return {
                'success': False,
                'error': '无法获取用户信息',
                'account_status': '异常',
                'account_status_desc': '无法获取用户信息，可能是网络问题或会话无效',
                'session_file': session_file
            }

       
        #logger.info(f"[代理] user_id={user_id} proxy={proxy}")

        client = await tdesk.ToTelethon(
            session=session_file,
            flag=UseCurrentSession,
            api=API.TelegramDesktop,
            proxy=proxy,
            connection=ConnectionTcpObfuscated,
            auto_reconnect=True,
            sequential_updates=True,
            request_retries=5,
            connection_retries=5,
        )
      

    except OpenTeleException as e:

        logger.info(f"[警告] TDesktop初始化失败: {str(e)}，使用 auth_key 构建会话")

        from session_utils import SafeSQLiteSession, ensure_writable_session_file, safe_remove_session_file
        from telethon.crypto.authkey import AuthKey

        if session_file and os.path.exists(session_file):
            safe_remove_session_file(session_file)

        session_file = ensure_writable_session_file(session_file, user_id=user_id)

        session = SafeSQLiteSession(session_file)

        auth_key_bytes = bytes.fromhex(auth_key_hex)
        session.auth_key = AuthKey(auth_key_bytes)

        session.save()

        logger.info(f"[会话构建] auth_key session保存成功")

        client = TelegramClient(
            session,
            api_id,
            api_hash,
            connection=ConnectionTcpObfuscated,
            timeout=15,
            request_retries=2,
            connection_retries=2,
            retry_delay=2,
            auto_reconnect=True,
            sequential_updates=True,
            proxy=proxy,
            use_ipv6=False
        )

    try:

        
        await client.connect()
        
        # FloodWait 自动处理
        client.flood_sleep_threshold = 120

        # 心跳优化
        if hasattr(client, "_sender") and client._sender:
            client._sender._keepalive = 30
            
        is_authorized = await client.is_user_authorized()

        if not is_authorized:
            return {
                'success': False,
                'error': '会话未授权',
                'account_status': '未授权',
                'account_status_desc': '账号未授权或会话已失效',
                'session_file': session_file
            }

        try:
            user = await asyncio.wait_for(client.get_me(), timeout=5)

        except FloodWaitError as e:
            logger.warning(f"FloodWait {e.seconds}s user_id={user_id}")
            if e.seconds < 30:
                await asyncio.sleep(e.seconds)
            else:
                raise
            user = await client.get_me()

        if not user:
            return {
                'success': False,
                'error': '无法获取用户信息',
                'account_status': '异常',
                'account_status_desc': '无法获取用户信息',
                'session_file': session_file
            }

        full_user = await asyncio.wait_for(
            client(GetFullUserRequest(user.id)), timeout=5
        )

        if (
            hasattr(full_user, 'full_user')
            and hasattr(full_user.full_user, 'bot_verification')
            and full_user.full_user.bot_verification
        ):

            bot_ver = full_user.full_user.bot_verification
            description = getattr(bot_ver, 'description', '')

            if description and 'frozen' in description.lower():
                return {
                    'success': False,
                    'error': '账号被Telegram官方冻结',
                    'account_status': '冻结',
                    'account_status_desc': '账号被Telegram官方冻结',
                    'session_file': session_file
                }

        if full_user.users and len(full_user.users) > 0:
            user = full_user.users[0]
        else:
            return {
                'success': False,
                'error': '无法获取用户信息',
                'account_status': '异常',
                'account_status_desc': '无法解析用户信息',
                'session_file': session_file,
                'client': None
            }

        # 用户状态解析
        def parse_status(status):

            status_data = {
                "type": status.__class__.__name__,
                "is_online": False
            }

            if hasattr(status, 'expires'):
                status_data["is_online"] = True
                status_data["description"] = "在线"

            elif hasattr(status, 'was_online'):
                status_data["description"] = "离线"

            return status_data

        status_info = parse_status(user.status)

        # 国家解析
        country = "未知"

        if user.phone:
            try:
                phone = user.phone if user.phone.startswith('+') else '+' + user.phone
                phone_number = phonenumbers.parse(phone, None)
                country = geocoder.country_name_for_number(phone_number, "zh")
            except:
                pass

        account_status = '正常'
        account_status_desc = '账号状态正常'

        if hasattr(user, 'deleted') and user.deleted:
            account_status = '注销'
            account_status_desc = '账号已被用户主动注销'

        elif hasattr(user, 'restricted') and user.restricted:
            account_status = '冻结'
            account_status_desc = '账号被冻结'

        if account_status == '正常':
            connection_kept = True

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
            'session_file': session_file,
            'client': client
        }

        return result

    except AuthKeyError:

        return {
            'success': False,
            'error': '授权密钥无效',
            'account_status': '异常',
            'account_status_desc': '授权密钥无效',
            'session_file': session_file,
            'client': None
        }

    except asyncio.TimeoutError:

        return {
            'success': False,
            'error': '连接超时',
            'account_status': '异常',
            'account_status_desc': '连接超时',
            'session_file': session_file,
            'client': None
        }

    except Exception as e:

        error_str = str(e)

        account_status = '异常'
        account_status_desc = f'验证异常: {error_str}'

        if 'USER_DEACTIVATED' in error_str:
            account_status = '注销'

        elif 'USER_BANNED' in error_str:
            account_status = '封号'

        return {
            'success': False,
            'error': f'验证失败：{error_str}',
            'account_status': account_status,
            'account_status_desc': account_status_desc,
            'session_file': session_file,
            'client': None
        }

    finally:

        if not connection_kept:
            await client.disconnect()
