from dataclasses import dataclass
import os
from dotenv import load_dotenv
load_dotenv()
@dataclass
class Settings:
    mysql_host: str = os.getenv("MYSQL_HOST", "localhost")
    mysql_port: int = int(os.getenv("MYSQL_PORT", "3306"))
    mysql_db: str = os.getenv("MYSQL_DB", "dona_arteira")
    mysql_user: str = os.getenv("MYSQL_USER", "root")
    mysql_password: str = os.getenv("MYSQL_PASSWORD", "")
    ftp_host: str = os.getenv("FTP_HOST", "")
    ftp_user: str = os.getenv("FTP_USER", "")
    ftp_password: str = os.getenv("FTP_PASSWORD", "")
    ftp_base_path: str = os.getenv("FTP_BASE_PATH", "/")
    app_title: str = "Dona Arteira Gestão"
SETTINGS = Settings()
