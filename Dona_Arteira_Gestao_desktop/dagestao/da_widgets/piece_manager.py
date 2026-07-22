from PyQt6.QtWidgets import QDialog, QVBoxLayout, QTableWidget, QTableWidgetItem, QHBoxLayout, QPushButton
from db import SessionLocal
from models import Piece
from da_widgets.piece_form import PieceFormDialog
from da_widgets.package_manager import PackageManagerDialog
from decimal import Decimal, InvalidOperation
class PieceManagerWindow(QDialog):
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle("Gerenciar Peças")
        self.table = QTableWidget(0,4); self.table.setHorizontalHeaderLabels(["Código","Descrição","Preço Varejo","Preço Atacado"])
        layout = QVBoxLayout(self); layout.addWidget(self.table)
        btns = QHBoxLayout(); bt_add = QPushButton("Adicionar Peça"); bt_edit = QPushButton("Editar Peça"); bt_del = QPushButton("Excluir Peça"); bt_pkg = QPushButton("Editar Embalagens")
        bt_add.clicked.connect(self.add_piece); bt_edit.clicked.connect(self.edit_piece); bt_del.clicked.connect(self.del_piece); bt_pkg.clicked.connect(self.edit_packages)
        btns.addWidget(bt_add); btns.addWidget(bt_edit); btns.addWidget(bt_del); btns.addStretch(1); btns.addWidget(bt_pkg); layout.addLayout(btns); self.load()

    def _fmt2(v):
        if v is None: return ""
        try:
            return f"{float(v):.2f}"
        except Exception:
            return str(v)
    
    def _norm_code(c):
        if c is None:
            return ""
        s = str(c).strip()
        # 11001.0 -> 11001
        try:
            return str(int(Decimal(s)))
        except Exception:
            return s[:-2] if s.endswith(".0") else s
    
    def load(self):
        self.table.setSortingEnabled(False)
        self.table.setRowCount(0)
    
        headers = [
            "Código", "Descrição", "Preço Varejo", "Preço Atacado",
            "Altura (cm)", "Largura (cm)", "Profundidade (cm)",
            "Peso (g)", "Embalagem", "Em Estoque"
        ]
        self.table.setColumnCount(len(headers))
        self.table.setHorizontalHeaderLabels(headers)
    
        seen = set()
    
        with SessionLocal() as s:
            rows = (s.query(Piece)
                      .order_by(Piece.code.asc())
                      .all())
    
            for p in rows:
                code = getattr(p, "code", "")
    
                #if code in seen:
                #    continue
                #seen.add(code)
    
                r = self.table.rowCount()
                self.table.insertRow(r)
    
                desc  = getattr(p, "description", "") or ""
                varej = getattr(p, "retail_price", None)
                atac  = getattr(p, "wholesale_price", None)
                h     = getattr(p, "height_cm", None)
                w     = getattr(p, "width_cm", None)
                d     = getattr(p, "depth_cm", None)
                peso  = getattr(p, "weight_g", None)
    
                pkg_obj = getattr(p, "package", None)
                pkg = getattr(pkg_obj, "name", "") if pkg_obj is not None else ""
                instk = "Sim" if getattr(p, "in_stock", False) else "Não"
    
                self.table.setItem(r, 0, QTableWidgetItem(code))
                self.table.setItem(r, 1, QTableWidgetItem(desc))
                self.table.setItem(r, 2, QTableWidgetItem(varej))
                self.table.setItem(r, 3, QTableWidgetItem(atac))
                self.table.setItem(r, 4, QTableWidgetItem(h))
                self.table.setItem(r, 5, QTableWidgetItem(w))
                self.table.setItem(r, 6, QTableWidgetItem(d))
                self.table.setItem(r, 7, QTableWidgetItem(peso))
                self.table.setItem(r, 8, QTableWidgetItem(pkg))
                self.table.setItem(r, 9, QTableWidgetItem(instk))
    
        self.table.setSortingEnabled(True)
        self.table.sortItems(0)
    
    def _current_code(self):
        r = self.table.currentRow()
        return self.table.item(r,0).text() if r>=0 and self.table.item(r,0) else None
    def add_piece(self):
        if PieceFormDialog(self).exec(): self.load()
    def edit_piece(self):
        code = self._current_code()
        if not code: return
        with SessionLocal() as s:
            p = s.query(Piece).filter_by(code=code).first(); s.expunge_all()
        if PieceFormDialog(self, p).exec(): self.load()
    def del_piece(self):
        code = self._current_code()
        if not code: return
        with SessionLocal() as s:
            p = s.query(Piece).filter_by(code=code).first()
            if p: s.delete(p); s.commit()
        self.load()
    def edit_packages(self):
        PackageManagerDialog(self).exec()
