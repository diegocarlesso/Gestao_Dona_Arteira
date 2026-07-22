from PyQt6.QtWidgets import QWidget, QVBoxLayout, QTableWidget, QTableWidgetItem, QHBoxLayout, QPushButton, QMessageBox
from PyQt6.QtCore import Qt
import re
from db import SessionLocal
from models import Piece
from da_widgets.piece_form import PieceFormDialog

class PiecesTab(QWidget):
    COLS = ["Código", "Descrição", "Preço Varejo", "Preço Atacado",
            "Altura (cm)", "Largura (cm)", "Profundidade (cm)",
            "Peso (g)", "Embalagem", "Em Estoque"]
    def __init__(self, parent=None):
        super().__init__(parent)
        self.table = QTableWidget(0, len(self.COLS))
        self.table.setHorizontalHeaderLabels(self.COLS)
        self.table.setSelectionBehavior(self.table.SelectionBehavior.SelectRows)
        self.table.setEditTriggers(self.table.EditTrigger.NoEditTriggers)
        self.table.itemDoubleClicked.connect(self._on_double_clicked)

        btns = QHBoxLayout()
        self.bt_refresh = QPushButton("Atualizar lista")
        btns.addStretch(1)
        btns.addWidget(self.bt_refresh)

        layout = QVBoxLayout(self)
        layout.addWidget(self.table)
        layout.addLayout(btns)

        self.bt_refresh.clicked.connect(self.load)
        self.load()

    def fmt2(self, v):
        try:
            return f"{float(0 if v is None else v):.2f}"
        except Exception:
            return "0.00"

    def _normalize_code(self, code: str) -> str:
        # remove sufixo .0 que vem de importações com tipo numérico
        return re.sub(r"\.0$", "", str(code or "").strip())

    def _find_piece_by_code(self, code_norm: str):
        with SessionLocal() as s:
            # tenta encontrar por código exato ou com .0
            p = (s.query(Piece)
                   .filter(Piece.code.in_([code_norm, f"{code_norm}.0"]))  # cobre duplicados
                   .order_by(Piece.id.desc())
                   .first())
            if p:
                s.expunge(p)
            return p

    def _on_double_clicked(self, item):
        row = item.row()
        code_norm = self.table.item(row, 0).text().strip()
        p = self._find_piece_by_code(code_norm)
        if not p:
            QMessageBox.warning(self, "Peças", "Peça não encontrada.")
            return
        if PieceFormDialog(self, p).exec():
            self.load()

    def load(self):
        self.table.setRowCount(0)
        seen = set()
        with SessionLocal() as s:
            rows = (s.query(Piece)
                      .order_by(Piece.code.asc())
                      .all())
            # detach to avoid lazy loads later
            for r in rows: s.expunge(r)

        for p in rows:
            code_norm = self._normalize_code(getattr(p, 'code', ''))
            if code_norm in seen:
                continue  # esconde duplicados .0
            seen.add(code_norm)

            i = self.table.rowCount()
            self.table.insertRow(i)
            # 0..9 na ordem pedida
            self.table.setItem(i, 0, QTableWidgetItem(code_norm))
            self.table.setItem(i, 1, QTableWidgetItem(p.description or ""))
            self.table.setItem(i, 2, QTableWidgetItem(self.fmt2(getattr(p, 'retail_price', None))))
            self.table.setItem(i, 3, QTableWidgetItem(self.fmt2(getattr(p, 'wholesale_price', None))))
            self.table.setItem(i, 4, QTableWidgetItem(self.fmt2(getattr(p, 'height_cm', None))))
            self.table.setItem(i, 5, QTableWidgetItem(self.fmt2(getattr(p, 'width_cm',  None))))
            self.table.setItem(i, 6, QTableWidgetItem(self.fmt2(getattr(p, 'depth_cm',  None))))
            self.table.setItem(i, 7, QTableWidgetItem(self.fmt2(getattr(p, 'weight_g',  None))))
            pkg = getattr(p, "package", None)
            self.table.setItem(i, 8, QTableWidgetItem(getattr(pkg, 'name', "")))
            self.table.setItem(i, 9, QTableWidgetItem(str(int(getattr(p, 'in_stock', 0) or 0))))
        self.table.resizeColumnsToContents()
