from PyQt6.QtWidgets import QDialog, QVBoxLayout, QTableWidget, QTableWidgetItem, QHBoxLayout, QPushButton, QListWidget, QListWidgetItem, QLabel
from PyQt6.QtGui import QPixmap, QIcon
from db import SessionLocal
from models import Order
from storage import FTPStorage
from widgets.order_details import OrderDetailsDialog

class OrderManagerWindow(QDialog):
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle("Gerenciar Pedidos")
        self.ftp = FTPStorage()
        self.table = QTableWidget(0,6)
        self.table.setHorizontalHeaderLabels(["#","Cliente","Pedido","Entrega","Pagamento","Valor"])
        self.thumb_list = QListWidget()
        layout = QVBoxLayout(self)
        layout.addWidget(self.table)
        layout.addWidget(QLabel("Miniaturas dos itens do pedido selecionado:"))
        layout.addWidget(self.thumb_list)
        btns = QHBoxLayout()
        bt_details = QPushButton("Ver detalhes")
        bt_refresh = QPushButton("Atualizar")
        bt_close = QPushButton("Fechar")
        bt_details.clicked.connect(self.open_details)
        bt_refresh.clicked.connect(self.load)
        bt_close.clicked.connect(self.accept)
        btns.addStretch(1); btns.addWidget(bt_close); btns.addWidget(bt_details); btns.addWidget(bt_refresh)
        layout.addLayout(btns)
        self.table.currentCellChanged.connect(lambda *_: self.load_thumbs())
        self.load()

    def _icon_for_piece(self, piece):
        try:
            if piece.images:
                data = self.ftp.download_bytes(piece.images[0].ftp_path)
                pix = QPixmap(); pix.loadFromData(data)
                if not pix.isNull():
                    return QIcon(pix.scaledToHeight(64))
        except Exception:
            pass
        return QIcon()

    def load(self):
        self.table.setRowCount(0)
        with SessionLocal() as s:
            orders = s.query(Order).order_by(Order.id.desc()).all()
            for o in orders:
                r = self.table.rowCount(); self.table.insertRow(r)
                self.table.setItem(r,0,QTableWidgetItem(str(o.id)))
                self.table.setItem(r,1,QTableWidgetItem(o.client.name))
                self.table.setItem(r,2,QTableWidgetItem(str(o.order_date)))
                self.table.setItem(r,3,QTableWidgetItem(str(o.delivery_date or "")))
                self.table.setItem(r,4,QTableWidgetItem(o.payment_method.value))
                self.table.setItem(r,5,QTableWidgetItem(f"{o.payment_value:.2f}"))
        self.load_thumbs()

    def _current_order_id(self):
        r = self.table.currentRow()
        if r < 0: return None
        it = self.table.item(r,0)
        return int(it.text()) if it else None

    def load_thumbs(self):
        oid = self._current_order_id()
        self.thumb_list.clear()
        if oid is None: return
        with SessionLocal() as s:
            o = s.query(Order).get(oid)
            if not o: return
            for it in o.items:
                ico = self._icon_for_piece(it.piece)
                if not ico.isNull():
                    self.thumb_list.addItem(QListWidgetItem(ico, it.piece.code))

    def open_details(self):
        oid = self._current_order_id()
        if oid is None: return
        OrderDetailsDialog(self, oid).exec()
