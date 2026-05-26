# models.py
from dataclasses import dataclass, field, asdict
from typing import Optional,  Dict, Any
from datetime import datetime

@dataclass
class MessageModel:
    """消息数据模型"""
    message_id: int
    chat_id: int
    account_id: str
    sender_id: int
    sender_name: str
    message_text: str
    message_type: str  # text/photo/voice/video/document
    sender_username: Optional[str] = None
    media_path: Optional[str] = None
    is_outgoing: bool = False
    reply_to_msg_id: Optional[int] = None
    timestamp: datetime = field(default_factory=datetime.now)
    is_read: bool = False
    created_at: datetime = field(default_factory=datetime.now)
    
    def to_dict(self) -> Dict[str, Any]:
        """转换为字典"""
        data = asdict(self)
        for key, value in data.items():
            if isinstance(value, datetime):
                data[key] = value.isoformat()
        return data
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'MessageModel':
        """从字典创建实例"""
        # 处理datetime字段
        datetime_fields = ['timestamp', 'created_at']
        for field_name in datetime_fields:
            if field_name in data and data[field_name] and isinstance(data[field_name], str):
                try:
                    data[field_name] = datetime.fromisoformat(data[field_name].replace('Z', '+00:00'))
                except:
                    data[field_name] = datetime.now()
        
        return cls(**{k: v for k, v in data.items() if k in cls.__dataclass_fields__})

@dataclass
class ChatModel:
    """聊天会话模型"""
    chat_id: str
    account_id: str
    chat_type: str  # private/group/channel/supergroup
    title: str
    unread_count: int = 0
    is_bot: bool = False
    chat_data: Optional[str] = None
    id:Optional[int] = None
    username: Optional[str] = None
    last_message_id: Optional[int] = None
    last_message_time: Optional[datetime] = None
    avatar_path: Optional[str] = None
    participants_count: int = 0
    created_at: datetime = field(default_factory=datetime.now)
    updated_at: datetime = field(default_factory=datetime.now)
    
    def to_dict(self) -> Dict[str, Any]:
        """转换为字典"""
        data = asdict(self)
        result = {}
        for key, value in data.items():
            if key == 'chat_id':
                result['chat_id'] = value  # 确保这个键存在
            elif isinstance(value, datetime):
                result[key] = value.isoformat()
            else:
                result[key] = value
        return result
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'ChatModel':
        """从字典创建实例"""
        # 处理datetime字段
        datetime_fields = ['last_message_time', 'created_at', 'updated_at']
        for field_name in datetime_fields:
            if field_name in data and data[field_name] and isinstance(data[field_name], str):
                try:
                    data[field_name] = datetime.fromisoformat(data[field_name].replace('Z', '+00:00'))
                except:
                    data[field_name] = datetime.now()
        
        return cls(**{k: v for k, v in data.items() if k in cls.__dataclass_fields__})
        
        
@dataclass
class GroupMemberModel:
    """群组成员模型"""
    member_id: int  # 用户ID
    chat_id: str    # 聊天ID（标准化后的）
    username: Optional[str] = None
    first_name: Optional[str] = None
    last_name: Optional[str] = None
    phone: Optional[str] = None
    role: str = 'member'  # member/admin/creator
    joined_at: datetime = field(default_factory=datetime.now)
    left_at: Optional[datetime] = None
    is_active: bool = True
    is_bot: bool = False
    last_seen: Optional[datetime] = None
    created_at: datetime = field(default_factory=datetime.now)
    updated_at: datetime = field(default_factory=datetime.now)
    
    def to_dict(self) -> Dict[str, Any]:
        """转换为字典"""
        data = asdict(self)
        result = {}
        for key, value in data.items():
            if isinstance(value, datetime):
                result[key] = value.isoformat()
            else:
                result[key] = value
        return result
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'GroupMemberModel':
        """从字典创建实例"""
        # 处理datetime字段
        datetime_fields = ['joined_at', 'left_at', 'last_seen', 'created_at', 'updated_at']
        for field_name in datetime_fields:
            if field_name in data and data[field_name] and isinstance(data[field_name], str):
                try:
                    data[field_name] = datetime.fromisoformat(data[field_name].replace('Z', '+00:00'))
                except:
                    data[field_name] = None
        
        # 只使用类中定义的字段
        model_fields = cls.__dataclass_fields__.keys()
        filtered_data = {k: v for k, v in data.items() if k in model_fields}
        
        return cls(**filtered_data)