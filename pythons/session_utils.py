import os
import tempfile
from typing import List, Optional

import sqlite3
import logging

from telethon.sessions import SQLiteSession


logger = logging.getLogger(__name__)


def _is_dir_writable(path: str) -> bool:
    try:
        os.makedirs(path, exist_ok=True)
        test_path = os.path.join(path, ".write_test")
        with open(test_path, "w", encoding="utf-8") as f:
            f.write("ok")
        os.remove(test_path)
        return True
    except Exception:
        return False


def get_telethon_session_dir(tdata_path: str) -> str:
    # 先看环境变量
    configured = os.getenv("TELETHON_SESSION_DIR")
    if configured and _is_dir_writable(configured):
        return configured
    # 如果没环境变量 → 用 tdata_path
    if tdata_path and os.path.isdir(tdata_path) and _is_dir_writable(tdata_path):
        return tdata_path
    # 如果都不行 → 用系统临时目录
    fallback = os.path.join(tempfile.gettempdir(), "telethon_sessions")
    os.makedirs(fallback, exist_ok=True)
    return fallback


def resolve_session_file(tdata_path: str, user_id: str | int) -> str:
    session_dir = get_telethon_session_dir(tdata_path)
    return os.path.join(session_dir, f"temp_{user_id}.session")


def ensure_writable_session_file(session_file: str, *, user_id: str | int | None = None) -> str:
    if not session_file:
        return session_file

    session_dir = os.path.dirname(session_file) or "."
    if not _is_dir_writable(session_dir) and user_id is not None:
        fallback = os.path.join(tempfile.gettempdir(), "telethon_sessions")
        os.makedirs(fallback, exist_ok=True)
        session_file = os.path.join(fallback, f"temp_{user_id}.session")
        session_dir = os.path.dirname(session_file) or "."
        os.makedirs(session_dir, exist_ok=True)

    try:
        if os.path.exists(session_file):
            try:
                os.chmod(session_file, 0o600)
            except Exception:
                pass
        with open(session_file, "a", encoding="utf-8"):
            pass
    except Exception:
        if user_id is not None:
            fallback = os.path.join(tempfile.gettempdir(), "telethon_sessions")
            os.makedirs(fallback, exist_ok=True)
            session_file = os.path.join(fallback, f"temp_{user_id}.session")
            try:
                with open(session_file, "a", encoding="utf-8"):
                    pass
            except Exception:
                pass
    return session_file


def list_temp_session_files(tdata_path: str) -> List[str]:
    session_dir = get_telethon_session_dir(tdata_path)
    try:
        names = os.listdir(session_dir)
    except Exception:
        return []
    out: List[str] = []
    for name in names:
        if name.startswith("temp_") and name.endswith(".session"):
            out.append(os.path.join(session_dir, name))
    return out


def pick_any_session_file(tdata_path: str) -> Optional[str]:
    files = list_temp_session_files(tdata_path)
    files = [p for p in files if os.path.isfile(p)]
    return files[0] if files else None


def safe_remove_session_file(path: str) -> bool:
    if not path:
        return False
    if not os.path.exists(path):
        return False
    try:
        try:
            os.chmod(path, 0o600)
        except Exception:
            pass
        os.remove(path)
        return True
    except Exception:
        return False


class SafeSQLiteSession(SQLiteSession):
    def process_entities(self, tlo):
        try:
            return super().process_entities(tlo)
        except sqlite3.OperationalError as e:
            logger.error(f"session.process_entities 写入失败: {e}")
            return None

    def save(self):
        try:
            return super().save()
        except sqlite3.OperationalError as e:
            logger.error(f"session.save 写入失败: {e}")
            return None

    def set_update_state(self, entity_id, state):
        try:
            return super().set_update_state(entity_id, state)
        except sqlite3.OperationalError as e:
            logger.error(f"session.set_update_state 写入失败: {e}")
            return None
