from ftplib import FTP
from io import BytesIO
from config import SETTINGS
class FTPStorage:
    def __init__(self):
        self.host = SETTINGS.ftp_host; self.user = SETTINGS.ftp_user
        self.password = SETTINGS.ftp_password; self.base = SETTINGS.ftp_base_path.rstrip('/') or '/'
    def _connect(self):
        ftp = FTP(self.host, timeout=30); ftp.login(self.user, self.password); return ftp
    def upload_bytes(self, rel_path: str, data: bytes):
        rel_path = rel_path.lstrip('/'); dirs = rel_path.split('/')[:-1]; filename = rel_path.split('/')[-1]
        ftp = self._connect()
        try:
            ftp.cwd(self.base)
            for d in dirs:
                try: ftp.mkd(d)
                except Exception: pass
                ftp.cwd(d)
            bio = BytesIO(data); ftp.storbinary(f"STOR {filename}", bio)
        finally:
            try: ftp.quit()
            except Exception: pass
        return rel_path
    def download_bytes(self, rel_path: str) -> bytes:
        rel_path = rel_path.lstrip('/'); ftp = self._connect(); bio = BytesIO()
        try:
            ftp.cwd(self.base)
            for d in rel_path.split('/')[:-1]: ftp.cwd(d)
            ftp.retrbinary(f"RETR {rel_path.split('/')[-1]}", bio.write); return bio.getvalue()
        finally:
            try: ftp.quit()
            except Exception: pass
