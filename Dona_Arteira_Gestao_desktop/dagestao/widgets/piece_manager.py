from PyQt6.QtWidgets import QDialog, QVBoxLayout, QTableWidget, QTableWidgetItem, QHBoxLayout, QPushButton
from db import SessionLocal
from models import Piece
from widgets.piece_form import PieceFormDialog
from widgets.package_manager import PackageManagerDialog
class PieceManagerWindow(QDialog):
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle("Gerenciar Peças")
        self.table = QTableWidget(0,4); self.table.setHorizontalHeaderLabels(["Código","Descrição","Preço Varejo","Preço Atacado"])
        layout = QVBoxLayout(self); layout.addWidget(self.table)
        btns = QHBoxLayout(); bt_add = QPushButton("Adicionar Peça"); bt_edit = QPushButton("Editar Peça"); bt_del = QPushButton("Excluir Peça"); bt_pkg = QPushButton("Editar Embalagens")
        bt_add.clicked.connect(self.add_piece); bt_edit.clicked.connect(self.edit_piece); bt_del.clicked.connect(self.del_piece); bt_pkg.clicked.connect(self.edit_packages)
        btns.addWidget(bt_add); btns.addWidget(bt_edit); btns.addWidget(bt_del); btns.addStretch(1); btns.addWidget(bt_pkg); layout.addLayout(btns); self.load()
    def load(self):
        self.table.setRowCount(0)
        with SessionLocal() as s:
            for p in s.query(Piece).order_by(Piece.code).all():
                r = self.table.rowCount(); self.table.insertRow(r)
                self.table.setItem(r,0,QTableWidgetItem(p.code)); self.table.setItem(r,1,QTableWidgetItem(p.description))
                self.table.setItem(r,2,QTableWidgetItem(f"{p.retail_price:.2f}")); self.table.setItem(r,3,QTableWidgetItem(f"{p.wholesale_price:.2f}"))
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
