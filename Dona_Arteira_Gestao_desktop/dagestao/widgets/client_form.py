from PyQt6.QtWidgets import QDialog, QFormLayout, QLineEdit, QHBoxLayout, QLabel, QPushButton, QComboBox, QVBoxLayout
from PyQt6.QtCore import Qt
from validators import is_cpf_cnpj_valid
from models import Client
from db import SessionLocal
UF = ["AC","AL","AP","AM","BA","CE","DF","ES","GO","MA","MT","MS","MG","PA","PB","PR","PE","PI","RJ","RN","RS","RO","RR","SC","SP","SE","TO"]
class ClientFormDialog(QDialog):
    def __init__(self, parent=None, client: Client|None=None):
        super().__init__(parent); self.setWindowTitle("Novo Cliente" if client is None else "Editar Cliente")
        self.client = client; self.valid_label = QLabel(""); self.valid_label.setFixedWidth(20); self.valid_label.setAlignment(Qt.AlignmentFlag.AlignCenter)
        self._build_ui(); 
        if client: self._fill(client)
    def _build_ui(self):
        layout = QVBoxLayout(self); form = QFormLayout()
        self.ed_name = QLineEdit(); self.ed_phone = QLineEdit(); self.ed_phone.setInputMask("(00)00000-0000;_")
        self.ed_street = QLineEdit(); self.ed_number = QLineEdit(); self.ed_bairro = QLineEdit(); self.ed_city = QLineEdit()
        self.cb_state = QComboBox(); self.cb_state.addItems(UF)
        self.ed_doc = QLineEdit(); self.ed_doc.setPlaceholderText("CPF ou CNPJ"); self.ed_doc.textChanged.connect(self._on_doc_changed)
        doc_layout = QHBoxLayout(); doc_layout.addWidget(self.ed_doc, 1); doc_layout.addWidget(self.valid_label)
        form.addRow("Nome:", self.ed_name); form.addRow("Telefone:", self.ed_phone)
        form.addRow("Rua:", self.ed_street); form.addRow("Número:", self.ed_number)
        form.addRow("Bairro:", self.ed_bairro); form.addRow("Cidade:", self.ed_city)
        form.addRow("Estado:", self.cb_state); form.addRow("CPF/CNPJ:", doc_layout)
        layout.addLayout(form)
        btns = QHBoxLayout(); self.bt_ok = QPushButton("Salvar"); bt_cancel = QPushButton("Cancelar")
        self.bt_ok.clicked.connect(self._save); bt_cancel.clicked.connect(self.reject)
        btns.addStretch(1); btns.addWidget(bt_cancel); btns.addWidget(self.bt_ok); layout.addLayout(btns); self.setMinimumWidth(480)
    def _on_doc_changed(self, txt: str):
        from styles import PALETTE
        ok = is_cpf_cnpj_valid(txt)
        if txt.strip()=="": self.valid_label.setText(""); return
        self.valid_label.setText("✔" if ok else "✖"); self.valid_label.setStyleSheet(f"color: {PALETTE['ok'] if ok else PALETTE['danger']}; font-weight: 900;")
    def _fill(self, c: Client):
        self.ed_name.setText(c.name or ""); self.ed_phone.setText(c.phone or ""); self.ed_street.setText(c.street or "")
        self.ed_number.setText(c.number or ""); self.ed_bairro.setText(c.neighborhood or ""); self.ed_city.setText(c.city or "")
        if c.state: self.cb_state.setCurrentText(c.state); self.ed_doc.setText(c.cpf_cnpj or "")
    def _save(self):
        if not is_cpf_cnpj_valid(self.ed_doc.text()):
            from styles import PALETTE
            self.valid_label.setText("✖"); self.valid_label.setStyleSheet(f"color: {PALETTE['danger']}; font-weight: 900;"); return
        from db import SessionLocal
        with SessionLocal() as s:
            if self.client is None: self.client = Client(); s.add(self.client)
            self.client.name = self.ed_name.text().strip(); self.client.phone = self.ed_phone.text().strip()
            self.client.street = self.ed_street.text().strip(); self.client.number = self.ed_number.text().strip()
            self.client.neighborhood = self.ed_bairro.text().strip(); self.client.city = self.ed_city.text().strip()
            self.client.state = self.cb_state.currentText(); self.client.cpf_cnpj = self.ed_doc.text().strip(); s.commit()
        self.accept()
