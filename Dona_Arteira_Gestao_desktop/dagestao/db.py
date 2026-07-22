from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker, declarative_base
from config import SETTINGS
from sqlalchemy import text
from sqlalchemy import inspect, text
Base = declarative_base()
def get_engine():
    url = (f"mysql+pymysql://{SETTINGS.mysql_user}:{SETTINGS.mysql_password}"
           f"@{SETTINGS.mysql_host}:{SETTINGS.mysql_port}/{SETTINGS.mysql_db}?charset=utf8mb4")
    return create_engine(url, pool_pre_ping=True, pool_recycle=280, future=True)
ENGINE = get_engine()
SessionLocal = sessionmaker(bind=ENGINE, autoflush=False, autocommit=False, future=True)


def _migrate_schema(conn):
    insp = inspect(conn)

    def columns(table):
        try:
            return {c["name"] for c in insp.get_columns(table)}
        except Exception:
            return set()

    def has_column(table, col):
        return col in columns(table)

    def index_exists(table, idx_name):
        try:
            return any(ix["name"] == idx_name for ix in insp.get_indexes(table))
        except Exception:
            return False

    # --- Tabela pieces: garantir colunas de preço no padrão novo ---
    pcs_cols = columns("pieces")

    # price_retail: renomear retail_price -> price_retail, ou criar se não existir
    if "price_retail" not in pcs_cols:
        if "retail_price" in pcs_cols:
            conn.exec_driver_sql(
                "ALTER TABLE pieces CHANGE COLUMN retail_price price_retail DECIMAL(10,2) DEFAULT 0"
            )
        else:
            conn.exec_driver_sql(
                "ALTER TABLE pieces ADD COLUMN price_retail DECIMAL(10,2) DEFAULT 0"
            )

    # price_wholesale: renomear wholesale_price -> price_wholesale, ou criar se não existir
    pcs_cols = columns("pieces")  # recarrega após alteração
    if "price_wholesale" not in pcs_cols:
        if "wholesale_price" in pcs_cols:
            conn.exec_driver_sql(
                "ALTER TABLE pieces CHANGE COLUMN wholesale_price price_wholesale DECIMAL(10,2) DEFAULT 0"
            )
        else:
            conn.exec_driver_sql(
                "ALTER TABLE pieces ADD COLUMN price_wholesale DECIMAL(10,2) DEFAULT 0"
            )

    # (Opcional) outras colunas usadas na UI, só adiciona se faltarem
    for col_def in [
        ("height_cm", "FLOAT DEFAULT 0"),
        ("width_cm", "FLOAT DEFAULT 0"),
        ("depth_cm", "FLOAT DEFAULT 0"),
        ("weight_g", "INT DEFAULT 0"),
        ("in_stock", "INT DEFAULT 0"),
        # se você tem tabela de embalagens/relacionamento, ajuste o tipo/constraint
        ("package", "VARCHAR(255) DEFAULT ''")
    ]:
        name, ddl = col_def
        pcs_cols = columns("pieces")
        if name not in pcs_cols:
            conn.exec_driver_sql(f"ALTER TABLE pieces ADD COLUMN {name} {ddl}")

    # --- Índices: só criar se a coluna existir e o índice ainda não existir ---
    if has_column("orders", "date") and not index_exists("orders", "idx_orders_date"):
        conn.exec_driver_sql("CREATE INDEX idx_orders_date ON orders(`date`)")



def init_db():
    from models import Client, Package, Piece, PieceImage, Order, OrderItem  # noqa
    Base.metadata.create_all(ENGINE)
    with ENGINE.begin() as conn:
        _migrate_schema(conn)
    # Embalagens padrão
    from models import Package
    with SessionLocal() as s:
        if s.query(Package).count() == 0:
            s.add_all([
                Package(name="1", height_cm=18, width_cm=21, depth_cm=20, weight_g=130),
                Package(name="2", height_cm=25, width_cm=27, depth_cm=25, weight_g=240),
                Package(name="3", height_cm=28, width_cm=30, depth_cm=22, weight_g=270),
                Package(name="4", height_cm=40, width_cm=35, depth_cm=30, weight_g=350),
                Package(name="5", height_cm=30, width_cm=60, depth_cm=30, weight_g=450),
            ])
            s.commit()

