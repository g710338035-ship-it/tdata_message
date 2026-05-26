import argparse
import json
import time
from opentele.td import TDesktop
from opentele.tl import TelegramClient
from opentele.tl.types import InputPeerChat, InputPeerChannel, InputMessageID
from opentele.tl.functions.messages import SendMessageRequest, ForwardMessagesRequest
from opentele.tl.functions.photos import UploadProfilePhotoRequest  # 用于图片处理
from opentele.helpers import generate_random_id

def main():
    # 解析命令行参数
    parser = argparse.ArgumentParser(description='使用opentele发送Telegram消息')
    parser.add_argument('--tdata_path', required=True, help='tdata文件夹路径')
    parser.add_argument('--group_id', required=True, help='目标群组ID')
    parser.add_argument('--send_type', required=True, help='消息类型(text/forward/image/voice)')
    parser.add_argument('--text', help='文本消息内容')
    parser.add_argument('--forward_id', help='转发消息ID(格式:chat_id_msg_id)')
    parser.add_argument('--images', help='图片路径列表(JSON格式)')
    parser.add_argument('--voice', help='语音文件路径列表(JSON格式)')
    parser.add_argument('--delay', type=int, default=1, help='发送前延迟(秒)')
    parser.add_argument('--api_id', required=True, type=int, help='Telegram API ID')
    parser.add_argument('--api_hash', required=True, help='Telegram API Hash')
    parser.add_argument('--proxy_type', help='代理类型(socks5/http)')
    parser.add_argument('--proxy_host', help='代理主机地址')
    parser.add_argument('--proxy_port', type=int, help='代理端口')
    parser.add_argument('--proxy_user', help='代理用户名')
    parser.add_argument('--proxy_pass', help='代理密码')
    
    args = parser.parse_args()
    
    try:
        # 1. 加载TDATA会话
        tdesk = TDesktop(args.tdata_path)
        client = tdesk.to_telegram_client()
        
        # 2. 配置代理
        if args.proxy_host and args.proxy_port:
            proxy_settings = {
                "proxy_type": args.proxy_type or "socks5",
                "addr": args.proxy_host,
                "port": args.proxy_port,
                "username": args.proxy_user,
                "password": args.proxy_pass
            }
            client.set_proxy(proxy_settings)
        
        # 3. 转换群组ID为正确格式
        group_id = int(args.group_id)
        # 确定群组类型（普通群/超级群）
        if group_id < 0:
            # 超级群/频道
            peer = InputPeerChannel(channel_id=abs(group_id), access_hash=0)  # access_hash可通过API获取
        else:
            # 普通群聊
            peer = InputPeerChat(chat_id=group_id)
        
        # 4. 连接客户端
        client.connect()
        if not client.is_connected():
            raise Exception("无法连接到Telegram服务器")
        
        # 处理发送延迟
        time.sleep(args.delay)
        
        # 5. 根据消息类型发送
        if args.send_type == 'text':
            if not args.text:
                raise Exception("文本消息内容不能为空")
            # 发送文本消息
            result = client(SendMessageRequest(
                peer=peer,
                message=args.text,
                random_id=generate_random_id()
            ))
            if not result:
                raise Exception("文本消息发送失败")
        
        elif args.send_type == 'forward':
            if not args.forward_id or '_' not in args.forward_id:
                raise Exception("转发消息ID格式错误，应为chat_id_msg_id")
            
            # 解析转发来源
            source_chat_id, msg_id = args.forward_id.split('_', 1)
            source_chat_id = int(source_chat_id)
            msg_id = int(msg_id)
            
            # 确定来源聊天类型
            if source_chat_id < 0:
                source_peer = InputPeerChannel(channel_id=abs(source_chat_id), access_hash=0)
            else:
                source_peer = InputPeerChat(chat_id=source_chat_id)
            
            # 转发消息
            result = client(ForwardMessagesRequest(
                to_peer=peer,
                from_peer=source_peer,
                id=[msg_id],
                random_id=[generate_random_id()]
            ))
            if not result:
                raise Exception("消息转发失败")
        
        elif args.send_type == 'image':
            if not args.images:
                raise Exception("图片列表不能为空")
            
            # 解析图片路径列表
            images = json.loads(args.images)
            if not isinstance(images, list) or len(images) == 0:
                raise Exception("图片列表格式错误")
            
            # 发送图片（支持多张）
            for img_path in images:
                # 使用opentele的文件上传功能
                upload_result = client.upload_file(img_path)
                if not upload_result:
                    raise Exception(f"图片上传失败: {img_path}")
                
                # 发送图片（带可选文字说明）
                result = client.send_file(
                    peer=peer,
                    file=upload_result,
                    caption=args.text or ""
                )
                if not result:
                    raise Exception(f"图片发送失败: {img_path}")
        
        elif args.send_type == 'voice':
            if not args.voice:
                raise Exception("语音列表不能为空")
            
            # 解析语音路径列表
            voices = json.loads(args.voice)
            if not isinstance(voices, list) or len(voices) == 0:
                raise Exception("语音列表格式错误")
            
            # 发送语音
            for voice_path in voices:
                upload_result = client.upload_file(voice_path)
                if not upload_result:
                    raise Exception(f"语音上传失败: {voice_path}")
                
                # 发送语音消息（标记为语音笔记）
                result = client.send_file(
                    peer=peer,
                    file=upload_result,
                    voice_note=True
                )
                if not result:
                    raise Exception(f"语音发送失败: {voice_path}")
        
        else:
            raise Exception(f"不支持的消息类型: {args.send_type}")
        
        # 6. 断开连接
        client.disconnect()
        
        # 返回成功结果
        print(json.dumps({
            'status': 'success',
            'message': f"{args.send_type}消息发送成功"
        }))
        exit(0)
        
    except Exception as e:
        # 返回错误信息
        print(json.dumps({
            'status': 'error',
            'message': str(e)
        }))
        exit(1)

if __name__ == '__main__':
    main()
