import argparse
import json
import asyncio
import traceback
from proxy_handler import parse_proxy, test_proxy
# 导入重构后的处理类
from tdata_processor import TelegramAccountHandler

async def main():
    try:
        # 解析命令行参数
        parser = argparse.ArgumentParser()
        parser.add_argument("--action", required=True, 
                          help="操作类型: check_tdata / get_account_info / set_online / set_offline / test_proxy")
        parser.add_argument("--tdata_path", help="tdata文件夹路径（check_tdata/get_account_info/set_*需要）")
        parser.add_argument("--proxy", help="代理信息，格式：protocol://username:password@ip:port")
        parser.add_argument("--api_id", type=int, required=True, help="Telegram API ID")
        parser.add_argument("--api_hash", required=True, help="Telegram API Hash")
        
         # 新增功能所需参数
        parser.add_argument("--current_password", help="当前密码（change_password需要）")
        parser.add_argument("--new_password", help="新密码（change_password需要）")
        parser.add_argument("--photo_path", help="头像图片路径（update_photo需要）")
        parser.add_argument("--first_name", help="名（update_nickname需要）")
        parser.add_argument("--last_name", help="姓（update_nickname可选）")
        parser.add_argument("--username", help="新用户名（update_username需要）")
        parser.add_argument("--bio", help="个人签名（update_bio需要）")
        
         # 消息发送相关参数
        parser.add_argument("--group_id", help="目标群组ID（send_messages需要，多个用逗号分隔）")
        parser.add_argument("--message_type", help="消息类型（send_messages需要：text/forward/image/voice）")
        parser.add_argument("--message_text", help="文本消息内容（message_type=text时需要）")
        parser.add_argument("--forward_id", help="转发消息ID（message_type=forward时需要，格式:chat_id_msg_id）")
        parser.add_argument("--media_paths", help="媒体文件路径（message_type=image/voice时需要，多个用逗号分隔）")
        parser.add_argument("--delay", type=int, default=1, help="发送延迟（秒）")
        parser.add_argument("--feedback_type", help="文本回复消息")
        parser.add_argument('--first_msg_id', type=int, default=0)
        
        parser.add_argument("--target_id", help="目标聊天ID（get_new_messages需要）")
        parser.add_argument("--last_msg_id", type=int, default=0, help="最后已知消息ID（get_new_messages用，默认0）")
        parser.add_argument("--timeout", type=int, default=3, help="超时时间（秒）")
        parser.add_argument("--last_check_time", type=int)
        parser.add_argument("--limit", type=int, default=50)
        parser.add_argument("--offset", type=int, default=0)
        
        args = parser.parse_args()
    
        # 根据操作类型分发处理
        try:
            if args.action in ["check_tdata", "get_account_info", "set_online", "set_offline","update_photo", "update_nickname", "update_username", "update_bio", "change_password","delete_all_contacts", "leave_all_groups", "logout_other_sessions", "send_messages",'get_groups','get_contacts','get_new_messages','get_history', "count_total_unread","mark_session_as_read", "block_user","delete_chat_history"]:
                # 实例化账号处理器
                handler = TelegramAccountHandler(
                    tdata_path=args.tdata_path,
                    api_id=args.api_id,
                    api_hash=args.api_hash,
                    proxy_str=args.proxy
                )
                
                # 根据不同操作调用对应方法
                if args.action == "check_tdata":
                    if not args.tdata_path:
                        raise ValueError("check_tdata需要指定--tdata_path")
                    result = await handler.check_tdata()
                    
                elif args.action == "get_groups":
                    if not args.tdata_path:
                        raise ValueError("get_groups需要指定--tdata_path")
                    result = await handler.get_groups()
                elif args.action == "get_contacts":
                    if not args.tdata_path:
                        raise ValueError("get_contacts需要指定--tdata_path")
                    result = await handler.get_contacts()
                # 在action判断分支中添加
                elif args.action == "get_new_messages":
                    if not all([args.tdata_path]):
                        raise ValueError("get_new_messages需要指定--tdata_path和--target_id")
                    result = await handler.get_new_messages(
                        target_id=args.target_id,
                        last_msg_id=int(args.last_msg_id) if args.last_msg_id else 0,
                        timeout=int(args.timeout) if args.timeout else 3
                    )
                    
                                
                elif args.action == "get_account_info":
                    if not args.tdata_path:
                        raise ValueError("get_account_info需要指定--tdata_path")
                    result = await handler.get_account_info()
                    
                elif args.action == "set_online":
                    if not args.tdata_path:
                        raise ValueError("set_online需要指定--tdata_path")
                    result = await handler.set_account_status("online")
                    
                elif args.action == "set_offline":
                    if not args.tdata_path:
                        raise ValueError("set_offline需要指定--tdata_path")
                    result = await handler.set_account_status("offline")
                elif args.action == "change_password":
                    if not all([args.tdata_path, args.current_password, args.new_password]):
                        raise ValueError("change_password需要指定--tdata_path、--current_password和--new_password")
                    result = await handler.change_password(args.current_password, args.new_password)
                    
                elif args.action == "update_photo":
                    if not all([args.tdata_path, args.photo_path]):
                        raise ValueError("update_photo需要指定--tdata_path和--photo_path")
                    result = await handler.update_profile_photo(args.photo_path)
                    
                elif args.action == "update_nickname":
                    if not all([args.tdata_path, args.first_name]):
                        raise ValueError("update_nickname需要指定--tdata_path和--first_name")
                    result = await handler.update_nickname(args.first_name, args.last_name or "")
                    
                elif args.action == "update_username":
                    if not all([args.tdata_path, args.username]):
                        raise ValueError("update_username需要指定--tdata_path和--username")
                    result = await handler.update_username(args.username)
                    
                elif args.action == "update_bio":
                    if not all([args.tdata_path, args.bio]):
                        raise ValueError("update_bio需要指定--tdata_path和--bio")
                    result = await handler.update_bio(args.bio)
                # 新增功能的调用
                elif args.action == "delete_all_contacts":
                    if not args.tdata_path:
                        raise ValueError("delete_all_contacts需要指定--tdata_path")
                    result = await handler.delete_all_contacts()
                    
                elif args.action == "leave_all_groups":
                    if not args.tdata_path:
                        raise ValueError("leave_all_groups需要指定--tdata_path")
                    result = await handler.leave_all_groups()
                    
                elif args.action == "logout_other_sessions":
                    if not args.tdata_path:
                        raise ValueError("logout_other_sessions需要指定--tdata_path")
                    result = await handler.logout_other_sessions()
                # 新增消息发送功能
                elif args.action == "send_messages":
                    if not all([args.tdata_path, args.group_id, args.message_type]):
                        raise ValueError("send_messages需要指定--tdata_path、--group_id和--message_type")
                        
                    # 验证消息类型对应的参数
                    if args.message_type == "text" and not args.message_text:
                        raise ValueError("message_type=text时需要指定--message_text")
                    if args.message_type == "forward" and not args.forward_id:
                        raise ValueError("message_type=forward时需要指定--forward_id")
                    if args.message_type in ["image", "voice"] and not args.media_paths:
                        raise ValueError(f"message_type={args.message_type}时需要指定--media_paths")
                        
                    # 处理群组ID（支持多个）
                    #group_ids = [gid.strip() for gid in args.group_id.split(',')]
                    
                    # 处理媒体文件路径（支持多个）
                    media_paths = []
                    if args.media_paths:
                        media_paths = [path.strip() for path in args.media_paths.split(',')]
                        
                    # 调用发送消息方法
                    result = await handler.send_messages(
                        group_ids=args.group_id,
                        message_type=args.message_type,
                        text=args.message_text,
                        forward_id=args.forward_id,
                        media_paths=media_paths,
                        delay=args.delay,
                        feedback_type=args.feedback_type,
                        first_msg_id=args.first_msg_id
                    )
                # 在action判断分支中添加
                elif args.action == "get_history":
                    if not all([args.tdata_path, args.target_id]):
                        raise ValueError("get_history需要指定--tdata_path和--target_id")
                    result = await handler.get_history(
                        target_id=args.target_id,
                        limit=int(args.limit) if args.limit else 50,
                        offset=int(args.offset) if args.offset else 0
                    )
                elif args.action == "count_total_unread":
                    if not args.tdata_path:
                        raise ValueError("count_total_unread需要指定--tdata_path")
                    result = await handler.count_total_unread()   
                elif args.action == "mark_session_as_read":
                    if not args.tdata_path:
                        raise ValueError("mark_session_as_read需要指定--session_id参数")
                    result = await handler.mark_session_as_read(args.group_id)
                elif args.action == "block_user":
                    if not args.tdata_path:
                        raise ValueError("block_user需要指定--session_id参数")
                    result = await handler.block_user(args.target_id)   
                elif args.action == "delete_chat_history":
                    if not args.tdata_path:
                        raise ValueError("delete_chat_history需要指定--session_id参数")
                    result = await handler.delete_chat_history(args.target_id)   
                    
            
            elif args.action in['get_common_groups','deleteUser']:
                 # 实例化账号处理器
                handler = TelegramAccountHandler(
                    tdata_path=args.tdata_path,
                    api_id=args.api_id,
                    api_hash=args.api_hash,
                    proxy_str=args.proxy
                )
                if args.action == "get_common_groups":
                    if not args.target_id:
                       raise ValueError("get_common_groups需要指定--tdata_path和--target_id")
                    result = await handler.get_common_groups(args.target_id)
                elif args.action == "deleteUser":
                    if not args.tdata_path:
                        raise ValueError("deleteUser需要指定--session_id参数")
                    result = await handler.deleteUser(args.target_id)       
            elif args.action == "test_proxy":
                if not args.proxy:
                    raise ValueError("test_proxy需要指定--proxy")
                proxy_info = parse_proxy(args.proxy)
                result = await test_proxy(proxy_info, args.api_id, args.api_hash)
                
            else:
                result = {"status": False, "message": "未知操作类型", "data": {}}
        except Exception as e:
            result = {"status": False, "message": str(e), "data": {}}
    except Exception as e:
        # 强制输出详细错误堆栈
        error_detail = traceback.format_exc()
        result = {
            "status": False,
            "message": f"操作失败: {str(e)}",
            "data": {"error_detail": error_detail}
        }
    # 输出JSON结果
    print(json.dumps(result, ensure_ascii=False, indent=2))
    #print(json.dumps(result))
if __name__ == "__main__":
    asyncio.run(main())
    