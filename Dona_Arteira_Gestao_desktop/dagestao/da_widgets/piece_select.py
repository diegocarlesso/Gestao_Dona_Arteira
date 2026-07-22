
from PyQt6.QtWidgets import QDialog, QVBoxLayout, QHBoxLayout, QLineEdit, QTableWidget, QTableWidgetItem, QPushButton
from PyQt6.QtCore import Qt
from db import SessionLocal
from models import Piece

class PieceSelectDialog(QDialog):
    """Dialogo para selecionar várias peças para um pedido."""
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle("Selecionar Itens (Peças)")
        self.resize(800, 500)
        self.table = QTableWidget(0, 4)
        self.table.setHorizontalHeaderLabels(["✔", "Código", "Descrição", "Preço Varejo"])
        self.table.setColumnWidth(0, 40)
        self.ed_filter = QLineEdit()
        self.ed_filter.setPlaceholderText("Filtrar por código/descrição...")
        self.ed_filter.textChanged.connect(self._apply_filter)
        self._all_rows_cache = []
        self._build_ui()
        self._load()

    def _build_ui(self):
        lay = QVBoxLayout(self)
        lay.addWidget(self.ed_filter)
        lay.addWidget(self.table)
        row = QHBoxLayout()
        bt_ok = QPushButton("Adicionar")
        bt_cancel = QPushButton("Cancelar")
        bt_ok.clicked.connect(self.accept)
        bt_cancel.clicked.connect(self.reject)
        row.addStretch(1)
        row.addWidget(bt_ok)
        row.addWidget(bt_cancel)
        lay.addLayout(row)

    def _load(self):
        self.table.setRowCount(0)
        self._all_rows_cache.clear()
        with SessionLocal() as s:
            pieces = (s.query(Piece).order_by(Piece.code.asc()).all())
            for p in pieces:
                code = str(getattr(p, "code", "") or "")
                # normaliza "123.0" -> "123"
                if code.endswith(".0"):
                    try:
                        code = str(int(float(code)))
                    except Exception:
                        pass
                desc = getattr(p, "description", "") or ""
                pr = getattr(p, "retail_price", 0.0) or 0.0
                r = self.table.rowCount()
                self.table.insertRow(r)
                chk = QTableWidgetItem("")
                chk.setFlags(Qt.ItemFlag.ItemIsUserCheckable | Qt.ItemFlag.ItemIsEnabled | Qt.ItemFlag.ItemIsSelectable)
                chk.setCheckState(Qt.CheckState.Unchecked)
                self.table.setItem(r, 0, chk)
                self.table.setItem(r, 1, QTableWidgetItem(code))
                self.table.setItem(r, 2, QTableWidgetItem(desc))
                self.table.setItem(r, 3, QTableWidgetItem(f"{float(pr):.2f}"))
                # guardar id da peça na coluna 1 (UserRole)
                self.table.item(r,1).setData(Qt.ItemDataRole.UserRole, getattr(p, "id", None))
                self._all_rows_cache.append((code.lower(), desc.lower()))

    def _apply_filter(self, text):
        t = (text or "").strip().lower()
        for r in range(self.table.rowCount()):
            code_l, desc_l = self._all_rows_cache[r]
            visible = (t in code_l) or (t in desc_l)
            self.table.setRowHidden(r, not visible)

    def selected_pieces(self):
        """Retorna uma lista de dicts: {'id': int, 'code': str, 'desc': str, 'price': float}"""
        sel = []
        for r in range(self.table.rowCount()):
            it = self.table.item(r, 0)
            if it and it.checkState() == Qt.CheckState.Checked:
                code_it = self.table.item(r, 1)
                desc_it = self.table.item(r, 2)
                price_it = self.table.item(r, 3)
                piece_id = code_it.data(Qt.ItemDataRole.UserRole)
                try:
                    price = float((price_it.text() or "0").replace(",", "."))
                except Exception:
                    price = 0.0
                sel.append({
                    "id": piece_id,
                    "code": code_it.text(),
                    "desc": desc_it.text(),
                    "price": price,
                })
        return sel
