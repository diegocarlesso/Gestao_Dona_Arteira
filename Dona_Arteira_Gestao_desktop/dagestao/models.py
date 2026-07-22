# models.py
from sqlalchemy import Column, Integer, String, Float, Text, ForeignKey, Date, Enum
from sqlalchemy.orm import relationship
from db import Base
import enum
from sqlalchemy import Column, Integer, String, Float, Text, ForeignKey

class Client(Base):
    __tablename__ = "clients"
    id = Column(Integer, primary_key=True)
    name = Column(String(120), nullable=False)
    phone = Column(String(20))
    street = Column(String(120))
    number = Column(String(20))
    neighborhood = Column(String(80))
    city = Column(String(80))
    state = Column(String(2))
    cpf_cnpj = Column(String(20), nullable=False, unique=True)
    # >>> novos campos
    cep = Column(String(9))
    notes = Column(Text, default="")
    # <<<
    orders = relationship("Order", back_populates="client", cascade="all, delete-orphan")

class Package(Base):
    __tablename__ = "packages"
    id = Column(Integer, primary_key=True)
    name = Column(String(40), nullable=False, unique=True)
    height_cm = Column(Float, default=0.0)
    width_cm  = Column(Float, default=0.0)
    depth_cm  = Column(Float, default=0.0)
    weight_g  = Column(Float, default=0.0)

class Piece(Base):
    __tablename__ = "pieces"
    id = Column(Integer, primary_key=True)
    code = Column(String(40), unique=True, nullable=False)
    description = Column(String(255), nullable=False)

    # mapeamentos já corrigidos:
    retail_price    = Column("price_retail", Float, default=0.0)
    wholesale_price = Column("price_wholesale", Float, default=0.0)

    height_cm = Column(Float, default=0.0)
    width_cm  = Column(Float, default=0.0)
    depth_cm  = Column(Float, default=0.0)
    weight_g  = Column(Float, default=0.0)

    # >>> NOVO CAMPO
    in_stock = Column(Integer, default=0)
    # <<<

    package_id = Column(Integer, ForeignKey("packages.id"))
    package_id = Column(Integer, ForeignKey("packages.id"))
    package = relationship("Package")
    images = relationship("PieceImage", back_populates="piece", cascade="all, delete-orphan")

class PieceImage(Base):
    __tablename__ = "piece_images"
    id = Column(Integer, primary_key=True)
    piece_id = Column(Integer, ForeignKey("pieces.id"), nullable=False)
    ftp_path = Column(String(255), nullable=False)
    piece = relationship("Piece", back_populates="images")
    
class PaymentMethod(enum.Enum):
    DINHEIRO = "Dinheiro"; PIX = "PIX"; CARTAO = "Cartão"; BOLETO = "Boleto"; OUTRO = "Outro"
    
class DeliveryMethod(enum.Enum):
    RETIRADA = "Retirada"; ENTREGA = "Entrega"
    
class Order(Base):
    __tablename__ = "orders"
    id = Column(Integer, primary_key=True)
    client_id = Column(Integer, ForeignKey("clients.id"), nullable=False)
    order_date = Column(Date, nullable=False)
    delivery_date = Column(Date, nullable=True)
    delivery_method = Column(Enum(DeliveryMethod), default=DeliveryMethod.RETIRADA)
    payment_method = Column(Enum(PaymentMethod), default=PaymentMethod.PIX)
    payment_value = Column(Float, default=0.0)
    notes = Column(Text, default="")
    client = relationship("Client", back_populates="orders")
    items = relationship("OrderItem", back_populates="order", cascade="all, delete-orphan")
    
class OrderItem(Base):
    __tablename__ = "order_items"
    id = Column(Integer, primary_key=True)
    order_id = Column(Integer, ForeignKey("orders.id"), nullable=False)
    piece_id = Column(Integer, ForeignKey("pieces.id"), nullable=False)
    quantity = Column(Integer, default=1)
    price = Column(Float, default=0.0)
    description = Column(String(255), default="")
    order = relationship("Order", back_populates="items")
    piece = relationship("Piece")
