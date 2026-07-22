from PyQt6.QtWidgets import QDialog, QVBoxLayout, QFormLayout, QComboBox, QDateEdit, QLineEdit, QTextEdit, QHBoxLayout, QPushButton, QTableWidget, QTableWidgetItem
from PyQt6.QtWidgets import QCompleter
from PyQt6.QtCore import QDate
from db import SessionLocal
from models import Order, OrderItem, PaymentMethod, DeliveryMethod, Piece
class OrderFormDialog(QDialog):
    def __init__(self, parent=None, client=None, order: Order|None=None):
        super().__init__(parent); self.client = client; self.order = order
        self.setWindowTitle("Novo Pedido" if order is None else "Editar Pedido"); self._build_ui()
        if order: self._fill(order)
    def _build_ui(self):
        layout = QVBoxLayout(self); form = QFormLayout()
        self.cb_delivery = QComboBox(); [self.cb_delivery.addItem(m.value, m) for m in DeliveryMethod]
        self.cb_payment = QComboBox(); [self.cb_payment.addItem(m.value, m) for m in PaymentMethod]
        self.ed_value = QLineEdit(); self.ed_value.setPlaceholderText("0.00")
        self.dt_order = QDateEdit(QDate.currentDate()); self.dt_order.setCalendarPopup(True)
        self.dt_delivery = QDateEdit(QDate.currentDate()); self.dt_delivery.setCalendarPopup(True)
        self.ed_notes = QTextEdit()
        form.addRow("Forma de entrega:", self.cb_delivery); form.addRow("Forma de pagamento:", self.cb_payment); form.addRow("Valor (R$):", self.ed_value)
        form.addRow("Data do pedido:", self.dt_order); form.addRow("Data da entrega:", self.dt_delivery); form.addRow("Observações:", self.ed_notes); layout.addLayout(form)
        self.table = QTableWidget(0,4); self.table.setHorizontalHeaderLabels(["Código","Descrição","Qtd","Preço (R$)"]); layout.addWidget(self.table)
        ib = QHBoxLayout(); bt_add = QPushButton("Adicionar item"); bt_del = QPushButton("Remover item"); bt_add.clicked.connect(self.add_item_row); bt_del.clicked.connect(self.del_item_row)
        ib.addWidget(bt_add); ib.addWidget(bt_del); ib.addStretch(1); layout.addLayout(ib)
        btns = QHBoxLayout(); bt_cancel = QPushButton("Cancelar"); bt_save = QPushButton("Salvar"); bt_cancel.clicked.connect(self.reject); bt_save.clicked.connect(self._save)
        btns.addStretch(1); btns.addWidget(bt_cancel); btns.addWidget(bt_save); layout.addLayout(btns); self.setMinimumWidth(720)

    def _piece_codes(self):
        with SessionLocal() as s:
            return [p.code for p in s.query(Piece).all()]

    def _apply_completer(self, row):
        completer = QCompleter(self._piece_codes())
        completer.setCaseSensitivity(False)
        self.table.item(row,0).setFlags(self.table.item(row,0).flags())
        editor = self.table.item(row,0)
        # Completer works when editing; we will re-fill on focus out by _maybe_autofill()

    def add_item_row(self):
        r = self.table.rowCount(); self.table.insertRow(r)
        for c, val in enumerate(["","","1","0.00"]):
            self.table.setItem(r,c,QTableWidgetItem(val))
        # trigger auto-fill after edits
        self.table.cellChanged.connect(self._maybe_autofill)
    def del_item_row(self):
        r = self.table.currentRow()
        if r>=0: self.table.removeRow(r)
    def _fill(self, o: Order):
        self.cb_delivery.setCurrentIndex(self.cb_delivery.findData(o.delivery_method))
        self.cb_payment.setCurrentIndex(self.cb_payment.findData(o.payment_method))
        self.ed_value.setText(f"{o.payment_value:.2f}")
        self.dt_order.setDate(QDate(o.order_date.year, o.order_date.month, o.order_date.day))
        if o.delivery_date: self.dt_delivery.setDate(QDate(o.delivery_date.year, o.delivery_date.month, o.delivery_date.day))
        self.ed_notes.setPlainText(o.notes or "")
        for it in o.items:
            r = self.table.rowCount(); self.table.insertRow(r)
            self.table.setItem(r,0,QTableWidgetItem(it.piece.code)); self.table.setItem(r,1,QTableWidgetItem(it.description or it.piece.description))
            self.table.setItem(r,2,QTableWidgetItem(str(it.quantity))); self.table.setItem(r,3,QTableWidgetItem(f"{it.price:.2f}"))
    def _save(self):
        from datetime import date
        with SessionLocal() as s:
            if self.order is None:
                o = Order(); o.client_id = self.client.id; s.add(o)
            else:
                o = s.merge(self.order)
            o.delivery_method = self.cb_delivery.currentData(); o.payment_method = self.cb_payment.currentData()
            o.payment_value = float(self.ed_value.text() or 0)
            o.order_date = date(self.dt_order.date().year(), self.dt_order.date().month(), self.dt_order.date().day())
            o.delivery_date = date(self.dt_delivery.date().year(), self.dt_delivery.date().month(), self.dt_delivery.date().day())
            o.notes = self.ed_notes.toPlainText()
            o.items.clear()
            for r in range(self.table.rowCount()):
                code = self.table.item(r,0).text().strip() if self.table.item(r,0) else ""
                if not code: continue
                piece = s.query(Piece).filter_by(code=code).first()
                if not piece: continue
                qty = int(float(self.table.item(r,2).text() or 1)); price = float(self.table.item(r,3).text() or 0)
                desc = self.table.item(r,1).text() if self.table.item(r,1) else piece.description
                o.items.append(OrderItem(piece=piece, quantity=qty, price=price, description=desc))
            s.commit()
        self.accept()


    def _maybe_autofill(self, row, col):
        # if code column edited, auto-fill desc and price (if piece exists)
        if col != 0: return
        code_item = self.table.item(row,0)
        if not code_item: return
        code = (code_item.text() or "").strip()
        if not code: return
        with SessionLocal() as s:
            piece = s.query(Piece).filter_by(code=code).first()
            if not piece: return
            # fill description and default price if empty/zero
            desc_item = self.table.item(row,1)
            if not desc_item or not (desc_item.text() or "").strip():
                self.table.setItem(row,1,QTableWidgetItem(piece.description or ""))
            price_item = self.table.item(row,3)
            try:
                price_val = float(price_item.text()) if price_item and price_item.text() else 0.0
            except Exception:
                price_val = 0.0
            if price_val == 0.0:
                self.table.setItem(row,3,QTableWidgetItem(f"{(piece.retail_price or 0.0):.2f}"))
