from PyQt6.QtWidgets import QDialog, QVBoxLayout, QListWidget, QPushButton, QHBoxLayout
from db import SessionLocal
from models import Package
class PackageSelectDialog(QDialog):
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle("Selecionar Embalagem")
        self.list = QListWidget(); self._ids = []
        layout = QVBoxLayout(self); layout.addWidget(self.list)
        btns = QHBoxLayout(); bt_cancel = QPushButton("Cancelar"); bt_ok = QPushButton("Selecionar")
        bt_cancel.clicked.connect(self.reject); bt_ok.clicked.connect(self.accept)
        btns.addStretch(1); btns.addWidget(bt_cancel); btns.addWidget(bt_ok); layout.addLayout(btns)
        self._load()
    def _load(self):
        with SessionLocal() as s:
            self.list.clear(); self._ids.clear()
            for p in s.query(Package).order_by(Package.name).all():
                self.list.addItem(p.label()); self._ids.append(p.id)
    def selected_id(self):
        r = self.list.currentRow()
        return self._ids[r] if r>=0 else None
