import os
import time
import errno

class AccountFileLock:
    def __init__(self, lock_dir: str, account_key: str, ttl_seconds: int = 600):
        self.lock_dir = os.path.abspath(lock_dir)
        self.account_key = account_key
        self.ttl_seconds = max(1, int(ttl_seconds))
        self._fd = None
        self._lock_path = os.path.join(self.lock_dir, f"{self.account_key}.lock")
        os.makedirs(self.lock_dir, exist_ok=True)

    def try_acquire(self) -> bool:
        now = int(time.time())
        try:
            fd = os.open(self._lock_path, os.O_CREAT | os.O_EXCL | os.O_RDWR)
            os.write(fd, f"{os.getpid()},{now}".encode("utf-8"))
            self._fd = fd
            return True
        except OSError as e:
            if e.errno != errno.EEXIST:
                return False

        try:
            st = os.stat(self._lock_path)
            if (now - int(st.st_mtime)) > self.ttl_seconds:
                try:
                    os.unlink(self._lock_path)
                except OSError:
                    return False
                try:
                    fd = os.open(self._lock_path, os.O_CREAT | os.O_EXCL | os.O_RDWR)
                    os.write(fd, f"{os.getpid()},{now}".encode("utf-8"))
                    self._fd = fd
                    return True
                except OSError:
                    return False
            return False
        except FileNotFoundError:
            try:
                fd = os.open(self._lock_path, os.O_CREAT | os.O_EXCL | os.O_RDWR)
                os.write(fd, f"{os.getpid()},{now}".encode("utf-8"))
                self._fd = fd
                return True
            except OSError:
                return False

    def release(self):
        try:
            if self._fd is not None:
                try:
                    os.close(self._fd)
                except OSError:
                    pass
                self._fd = None
            if os.path.exists(self._lock_path):
                try:
                    os.unlink(self._lock_path)
                except OSError:
                    pass
        finally:
            self._fd = None

    def __enter__(self):
        acquired = self.try_acquire()
        if not acquired:
            raise RuntimeError("account lock busy")
        return self

    def __exit__(self, exc_type, exc, tb):
        self.release()
        return False
