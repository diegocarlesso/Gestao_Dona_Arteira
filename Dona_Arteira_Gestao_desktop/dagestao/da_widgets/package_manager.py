from PyQt6.QtWidgets import QDialog, QVBoxLayout, QTableWidget, QTableWidgetItem, QHBoxLayout, QPushButton
from db import SessionLocal
from models import Package

class PackageManagerDialog(QDialog):
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle("Editar Embalagens")
        self.table = QTableWidget(0,5)
        self.table.setHorizontalHeaderLabels(["Embalagem","Altura(cm)","Largura(cm)","Profundidade(cm)","Peso(g)"])
        layout = QVBoxLayout(self)
        layout.addWidget(self.table)
        btns = QHBoxLayout()
        bt_add = QPushButton("Adicionar")
        bt_del = QPushButton("Excluir")
        bt_save = QPushButton("Salvar alterações")
        bt_add.clicked.connect(self.add_row)
        bt_del.clicked.connect(self.del_row)
        bt_save.clicked.connect(self.save)
        btns.addWidget(bt_add); btns.addWidget(bt_del); btns.addStretch(1); btns.addWidget(bt_save)
        layout.addLayout(btns)
        self.load()

    def load(self):
        self.table.setRowCount(0)
        with SessionLocal() as s:
            pkgs = s.query(Package).order_by(Package.name).all()
            for p in pkgs:
                r = self.table.rowCount(); self.table.insertRow(r)
                self.table.setItem(r,0,QTableWidgetItem(p.name))
                self.table.setItem(r,1,QTableWidgetItem(str(p.height_cm or 0)))
                self.table.setItem(r,2,QTableWidgetItem(str(p.width_cm or 0)))
                self.table.setItem(r,3,QTableWidgetItem(str(p.depth_cm or 0)))
                self.table.setItem(r,4,QTableWidgetItem(str(p.weight_g or 0)))

    def add_row(self):
        r = self.table.rowCount(); self.table.insertRow(r)

    def del_row(self):
        r = self.table.currentRow()
        if r >= 0: self.table.removeRow(r)

    def save(self):
        with SessionLocal() as s:
            s.query(Package).delete()
            s.commit()
            for r in range(self.table.rowCount()):
                name = (self.table.item(r,0).text() if self.table.item(r,0) else "").strip()
                if not name: continue
                def f(col):
                    it = self.table.item(r,col)
                    return float(it.text()) if it and it.text() else 0.0
                s.add(Package(name=name, height_cm=f(1), width_cm=f(2), depth_cm=f(3), weight_g=f(4)))
            s.commit()
        self.accept()
