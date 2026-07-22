from PyQt6.QtWidgets import QDialog, QVBoxLayout, QTableWidget, QTableWidgetItem, QHBoxLayout, QPushButton, QListWidget, QListWidgetItem, QMessageBox
from PyQt6.QtGui import QIcon, QPixmap
from PyQt6.QtCore import Qt
from sqlalchemy.orm import joinedload
from db import SessionLocal
from models import Order, OrderItem

class OrderManagerWindow(QDialog):
    def __init__(self, parent=None, client_id=None):
        super().__init__(parent)
        self.setWindowTitle("Gerenciar Pedidos" + (" - Cliente #{}".format(client_id) if client_id else ""))
        self.client_id = client_id

        self.table = QTableWidget(0, 6)
        self.table.setHorizontalHeaderLabels(["#", "Cliente", "Pedido", "Entrega", "Pagamento", "Valor"])
        self.table.setSelectionBehavior(self.table.SelectionBehavior.SelectRows)
        self.table.setEditTriggers(self.table.EditTrigger.NoEditTriggers)
        self.table.doubleClicked.connect(self.open_details)

        self.thumb_list = QListWidget()

        layout = QVBoxLayout(self)
        layout.addWidget(self.table)

        hb = QHBoxLayout()
        self.bt_edit = QPushButton("Editar Pedido")
        self.bt_view = QPushButton("Ver Detalhes")
        self.bt_remove = QPushButton("Remover Pedido")
        hb.addWidget(self.bt_edit); hb.addWidget(self.bt_view); hb.addStretch(1); hb.addWidget(self.bt_remove)
        layout.addLayout(hb)
        layout.addWidget(self.thumb_list)

        self.bt_edit.clicked.connect(self.edit_order)
        self.bt_view.clicked.connect(self.open_details)
        self.bt_remove.clicked.connect(self.remove_order)

        self.load()

    def _current_order_id(self):
        r = self.table.currentRow()
        if r < 0:
            return None
        try:
            return int(self.table.item(r, 0).text())
        except Exception:
            return None

    def load(self):
        self.table.setRowCount(0)
        self.thumb_list.clear()
        with SessionLocal() as s:
            q = (s.query(Order)
                   .options(joinedload(Order.client), joinedload(Order.items)))
            if self.client_id:
                q = q.filter(Order.client_id == self.client_id)
            rows = q.order_by(Order.order_date.desc()).all()
            # detach to keep icons later if needed
            for r in rows:
                s.expunge(r)
                if r.client: s.expunge(r.client)

        for o in rows:
            i = self.table.rowCount()
            self.table.insertRow(i)
            self.table.setItem(i, 0, QTableWidgetItem(str(o.id)))
            self.table.setItem(i, 1, QTableWidgetItem(o.client.name if o.client else ""))
            self.table.setItem(i, 2, QTableWidgetItem(str(getattr(o, "number", ""))))
            self.table.setItem(i, 3, QTableWidgetItem(o.delivery_method.value if getattr(o, "delivery_method", None) else ""))
            self.table.setItem(i, 4, QTableWidgetItem(o.payment_method.value if getattr(o, "payment_method", None) else ""))
            self.table.setItem(i, 5, QTableWidgetItem(f"{float(getattr(o,'payment_value',0) or 0):.2f}"))

        self.table.resizeColumnsToContents()

    def edit_order(self):
        oid = self._current_order_id()
        if oid is None:
            return
        from da_widgets.order_form import OrderFormDialog
        # load client on demand
        with SessionLocal() as s:
            o = s.get(Order, oid)
            if not o:
                QMessageBox.warning(self, "Editar Pedido", "Pedido não encontrado.")
                return
            if o.client: s.expunge(o.client)
        dlg = OrderFormDialog(self, client=o.client, order=o)
        if dlg.exec():
            self.load()

    def open_details(self):
        oid = self._current_order_id()
        if oid is None: return
        from da_widgets.order_details import OrderDetailsDialog
        OrderDetailsDialog(self, oid).exec()

    def remove_order(self):
        oid = self._current_order_id()
        if oid is None: return
        if QMessageBox.question(self, "Remover Pedido", "Confirma remover este pedido?") != QMessageBox.StandardButton.Yes:
            return
        with SessionLocal() as s:
            o = s.get(Order, oid)
            if not o:
                return
            # se não houver cascade no relacionamento:
            for it in list(getattr(o, 'items', []) or []):
                s.delete(it)
            s.delete(o)
            s.commit()
        self.load()
