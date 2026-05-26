import argparse
import json
import os
import asyncio
from datetime import datetime
from opentele.td import TDesktop
from opentele.api import UseCurrentSession
from opentele.exception import OpenTeleException
from telethon.tl.functions.contacts import GetContactsRequest
from telethon.tl.functions.channels import GetChannelsRequest
from telethon.tl.types import PeerChannel
import phonenumbers
from phonenumbers import geocoder
import glob

# 辅助函数：将Unix时间戳转换为格式化日期字符串
def timestamp_to_datetime(timestamp, format_str="%Y-%m-%d %H:%M:%S"):
    if not timestamp:
        return "未知时间"
    try:
        dt = datetime.fromtimestamp(timestamp)
        return dt.strftime(format_str)
    except Exception:
        return f"无效时间戳: {timestamp}"

# 解析用户状态对象
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

async def main():
    parser = argparse.ArgumentParser(description='提取Telegram账号详细信息')
    parser.add_argument('--tdata_path', required=True, help='tdata文件夹路径')
    parser.add_argument('--tdata_phone', required=True, help='tdata_phone')
    parser.add_argument('--api_id', required=True, type=int, help='Telegram API ID')
    parser.add_argument('--api_hash', required=True, help='Telegram API Hash')
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
        'avatar_url': None,
        'friends_count': 0,
        'groups_count': 0,
        'is_authorized': False,
        'online': 0,
    }

    try:
        tdata_folder = args.tdata_path
        tdata_phone = args.tdata_phone
        api_id = args.api_id
        api_hash = args.api_hash

        if not os.path.isdir(tdata_folder):
            result['message'] = f'tdata目录不存在: {tdata_folder}'
            print(json.dumps(result, ensure_ascii=False, indent=2))
            return

        # 删除之前的临时 session 文件，避免冲突
        temp_sessions = glob.glob(os.path.join(tdata_folder, "temp_*.session"))
        for f in temp_sessions:
            try:
                os.remove(f)
            except:
                pass

        # 加载 tdata
        try:
            tdesk = TDesktop(tdata_folder)
            tdesk.LoadTData()
            if not tdesk.accounts:
                result['message'] = "未找到任何账号数据"
                print(json.dumps(result, ensure_ascii=False, indent=2))
                return
        except OpenTeleException as e:
            result['message'] = f"加载tdata失败: {str(e)}"
            print(json.dumps(result, ensure_ascii=False, indent=2))
            return

        # 提取第一个账号
        account = tdesk.accounts[0]
        result['user_id'] = account.UserId if account.UserId else None
        result['auth_key'] = account.authKey.key.hex() if account.authKey else None
        session_path = os.path.join(tdata_folder, f"temp_{account.UserId}.session")

        # 转为 Telethon 客户端
        client = await tdesk.ToTelethon(
            session=session_path,
            flag=UseCurrentSession,
            api_id=api_id,
            api_hash=api_hash
        )
        await client.connect()

        is_authorized = await client.is_user_authorized()
        result['is_authorized'] = is_authorized

        if is_authorized:
            user = await client.get_me()
            status_info = parse_status(user.status)
            result['status'] = 1
            result['user_id'] = user.id
            result['username'] = user.username
            result['nickname'] = (user.first_name or "") + (" " + user.last_name if user.last_name else "")
            result['phone'] = user.phone
            result['status_info'] = status_info

            # 手机号国家信息
            if user.phone:
                try:
                    phone = user.phone if user.phone.startswith('+') else '+' + user.phone
                    phone_number = phonenumbers.parse(phone, None)
                    result['country'] = geocoder.country_name_for_number(phone_number, "zh")
                except:
                    result['country'] = "未知"

            # 在线状态消息
            if 'description' in status_info:
                result['message'] = status_info['description']
                if status_info.get('is_online'):
                    result['online'] = 1
            elif 'was_online' in status_info:
                last_online = timestamp_to_datetime(status_info['was_online'])
                result['message'] = f"最后在线时间: {last_online}"
            else:
                result['message'] = "未知状态"
        else:
            # 未授权：仅提取本地可用信息
            local_phone = None
            if hasattr(account, 'user') and account.user:
                user = account.user
                result['username'] = getattr(user, 'username', None)
                result['nickname'] = f"{getattr(user, 'first_name', '')} {getattr(user, 'last_name', '')}".strip()
                local_phone = getattr(user, 'phone', None)
            result['phone'] = local_phone if local_phone else tdata_phone

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
                    result['countryss'] = country_info
                    result['country'] = geocoder.country_name_for_number(phone_number, "zh")
                except:
                    result['country'] = "unknown"

            result['message'] = "账号未授权，已提取本地可用信息"

        await client.disconnect()
        print(json.dumps(result, ensure_ascii=False, indent=2))

    except Exception as e:
        result.update({
            'status': 0,
            'message': f'处理失败: {str(e)}',
            'auth_key': None,
            'avatar_url': None,
            'is_authorized': False
        })
        print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    asyncio.run(main())
