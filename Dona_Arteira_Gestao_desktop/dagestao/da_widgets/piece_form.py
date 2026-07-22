import os
from PyQt6.QtWidgets import QDialog, QVBoxLayout, QFormLayout, QLineEdit, QHBoxLayout, QPushButton, QComboBox, QFileDialog, QListWidget, QListWidgetItem, QLabel
from PyQt6.QtGui import QPixmap, QIcon
from io import BytesIO
from db import SessionLocal
from models import Piece, Package, PieceImage
from storage import FTPStorage
from da_widgets.package_select import PackageSelectDialog
from PyQt6.QtWidgets import QSpinBox, QLabel

class PieceFormDialog(QDialog):
    def __init__(self, parent=None, piece: Piece|None=None):
        super().__init__(parent)
        self.setWindowTitle("Nova Peça" if piece is None else "Editar Peça")
        self.editing = piece is not None
        self.piece = piece; self.ftp = FTPStorage()
        self._build_ui(); self._load_packages()
        if piece: self._fill(piece)
        self.resize(960, 680)
        self.setMinimumSize(900, 640)

    def _build_ui(self):
        layout = QVBoxLayout(self); form = QFormLayout()
        self.ed_code = QLineEdit(); self.ed_desc = QLineEdit()
        self.ed_pr_retail = QLineEdit(); self.ed_pr_retail.setPlaceholderText("0.00")
        self.ed_pr_wh = QLineEdit(); self.ed_pr_wh.setPlaceholderText("0.00")
        self.ed_h = QLineEdit(); self.ed_w = QLineEdit(); self.ed_d = QLineEdit(); self.ed_g = QLineEdit()
        self.cb_pkg = QComboBox(); self.bt_choose_pkg = QPushButton("Selecionar da lista…"); self.bt_choose_pkg.clicked.connect(self._choose_pkg)
        pkg_layout = QHBoxLayout(); pkg_layout.addWidget(self.cb_pkg, 1); pkg_layout.addWidget(self.bt_choose_pkg)
        form.addRow("CÓDIGO:", self.ed_code); form.addRow("DESCRIÇÃO:", self.ed_desc)
        form.addRow("PREÇO VAREJO (R$):", self.ed_pr_retail); form.addRow("PREÇO ATACADO (R$):", self.ed_pr_wh)
        form.addRow("ALTURA (cm):", self.ed_h); form.addRow("LARGURA (cm):", self.ed_w)
        form.addRow("PROFUNDIDADE (cm):", self.ed_d); form.addRow("PESO (g):", self.ed_g); form.addRow("EMBALAGEM:", pkg_layout)
        self.sp_in_stock = QSpinBox()
        self.sp_in_stock.setRange(0, 1_000_000)
        self.sp_in_stock.setValue(getattr(self.piece, "in_stock", 0) if self.editing else 0)
        
        form.addRow(QLabel("Em Estoque:"), self.sp_in_stock)
        
        layout.addLayout(form)
        self.list_imgs = QListWidget(); layout.addWidget(QLabel("Imagens:")); layout.addWidget(self.list_imgs)
        btns = QHBoxLayout(); bt_add_img = QPushButton("Adicionar imagem…"); bt_add_img.clicked.connect(self._add_image)
        bt_cancel = QPushButton("Cancelar"); bt_cancel.clicked.connect(self.reject)
        self.bt_save = QPushButton("Salvar"); self.bt_save.clicked.connect(self._save)
        btns.addWidget(bt_add_img); btns.addStretch(1); btns.addWidget(bt_cancel); btns.addWidget(self.bt_save); layout.addLayout(btns); self.setMinimumWidth(600)

    def _load_packages(self):
        with SessionLocal() as s:
            self.packages = s.query(Package).order_by(Package.name).all()
            self.cb_pkg.clear()
            for p in self.packages: self.cb_pkg.addItem(p.label(), p.id)

    def _choose_pkg(self):
        dlg = PackageSelectDialog(self)
        if dlg.exec():
            pid = dlg.selected_id()
            if pid is not None:
                idx = self.cb_pkg.findData(pid)
                if idx >= 0: self.cb_pkg.setCurrentIndex(idx)

    def _thumb_from_ftp(self, rel_path: str):
        try:
            data = self.ftp.download_bytes(rel_path)
            pix = QPixmap(); pix.loadFromData(data)
            if not pix.isNull():
                return QIcon(pix.scaledToHeight(48))
        except Exception:
            pass
        return QIcon()

    def _fill(self, p: Piece):
        self.ed_code.setText(p.code); self.ed_desc.setText(p.description)
        self.ed_pr_retail.setText(str(p.retail_price or 0)); self.ed_pr_wh.setText(str(p.wholesale_price or 0))
        self.ed_h.setText(str(p.height_cm or 0)); self.ed_w.setText(str(p.width_cm or 0))
        self.ed_d.setText(str(p.depth_cm or 0)); self.ed_g.setText(str(p.weight_g or 0))
        if p.package_id:
            idx = self.cb_pkg.findData(p.package_id)
            if idx >= 0: self.cb_pkg.setCurrentIndex(idx)
        self.list_imgs.clear()
        for img in p.images:
            it = QListWidgetItem(self._thumb_from_ftp(img.ftp_path), img.ftp_path)
            self.list_imgs.addItem(it)

    def _add_image(self):
        fn, _ = QFileDialog.getOpenFileName(self, "Selecionar imagem", "", "Imagens (*.png *.jpg *.jpeg *.webp)")
        if not fn: return
        with open(fn, "rb") as f: data = f.read()
        code = self.ed_code.text().strip() or "sem_codigo"; rel = f"pecas/{code}/{os.path.basename(fn)}"
        path = self.ftp.upload_bytes(rel, data)
        it = QListWidgetItem(self._thumb_from_ftp(path), path)
        self.list_imgs.addItem(it)

    def _save(self):
        with SessionLocal() as s:
            if self.piece is None: self.piece = Piece(); s.add(self.piece)
            p = self.piece
            p.code = self.ed_code.text().strip(); p.description = self.ed_desc.text().strip()
            p.retail_price = float(self.ed_pr_retail.text() or 0); p.wholesale_price = float(self.ed_pr_wh.text() or 0)
            p.height_cm = float(self.ed_h.text() or 0); p.width_cm = float(self.ed_w.text() or 0)
            p.depth_cm = float(self.ed_d.text() or 0); p.weight_g = float(self.ed_g.text() or 0)
            p.package_id = self.cb_pkg.currentData()
            p.in_stock = int(self.sp_in_stock.value())
            p.images.clear()
            for i in range(self.list_imgs.count()):
                s.add(PieceImage(piece=p, ftp_path=self.list_imgs.item(i).text()))
            s.commit()
        self.accept()
