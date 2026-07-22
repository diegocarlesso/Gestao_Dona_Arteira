from PyQt6.QtWidgets import QDialog, QVBoxLayout, QLabel, QListWidget, QListWidgetItem, QTableWidget, QTableWidgetItem, QHBoxLayout
from PyQt6.QtGui import QPixmap, QIcon
from db import SessionLocal
from models import Order
from storage import FTPStorage

class OrderDetailsDialog(QDialog):
    def __init__(self, parent=None, order_id: int|None=None):
        super().__init__(parent)
        self.setWindowTitle("Detalhes do Pedido")
        self.ftp = FTPStorage()
        self._build_ui()
        if order_id:
            self.load(order_id)

    def _build_ui(self):
        self.layout = QVBoxLayout(self)
        self.lbl = QLabel("")
        self.layout.addWidget(self.lbl)
        self.table = QTableWidget(0,4)
        self.table.setHorizontalHeaderLabels(["Código","Descrição","Qtd","Preço (R$)"])
        self.layout.addWidget(self.table)
        self.thumb_list = QListWidget()
        self.layout.addWidget(QLabel("Imagens dos itens:"))
        self.layout.addWidget(self.thumb_list)

    def _icon_for_piece(self, piece):
        # first image if exists
        try:
            if piece.images:
                data = self.ftp.download_bytes(piece.images[0].ftp_path)
                pix = QPixmap(); pix.loadFromData(data)
                if not pix.isNull():
                    return QIcon(pix.scaledToHeight(64))
        except Exception:
            pass
        return QIcon()

    def load(self, order_id: int):
        from models import Order
        with SessionLocal() as s:
            o = s.query(Order).get(order_id)
            if not o:
                return
            self.lbl.setText(f"Pedido #{o.id} de {o.client.name} — {o.order_date} → {o.delivery_date or ''} | {o.payment_method.value} R$ {o.payment_value:.2f}")
            self.table.setRowCount(0)
            self.thumb_list.clear()
            for it in o.items:
                r = self.table.rowCount(); self.table.insertRow(r)
                self.table.setItem(r,0,QTableWidgetItem(it.piece.code))
                self.table.setItem(r,1,QTableWidgetItem(it.description or it.piece.description))
                self.table.setItem(r,2,QTableWidgetItem(str(it.quantity)))
                self.table.setItem(r,3,QTableWidgetItem(f"{it.price:.2f}"))
                ico = self._icon_for_piece(it.piece)
                if not ico.isNull():
                    self.thumb_list.addItem(QListWidgetItem(ico, it.piece.code))
            s.expunge_all()
