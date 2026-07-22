
from PyQt6.QtWidgets import (
    QMainWindow, QWidget, QVBoxLayout, QHBoxLayout, QTreeWidget, QTreeWidgetItem,
    QTabWidget, QPushButton, QMessageBox
)
from PyQt6.QtGui import QIcon, QAction
from PyQt6.QtCore import Qt
import traceback, logging

# Estilo opcional
try:
    from styles import STYLE_SHEET
except Exception:
    STYLE_SHEET = ""

from sqlalchemy import select, func
from sqlalchemy.orm import joinedload

from db import SessionLocal
from models import Client, Order, OrderItem
from da_widgets.client_form import ClientFormDialog
from da_widgets.piece_manager import PieceManagerWindow
from da_widgets.order_form import OrderFormDialog
from da_widgets.order_manager import OrderManagerWindow
from da_widgets.order_details import OrderDetailsDialog

def _qt_excepthook(exctype, value, tb):
    logging.exception("Unhandled exception", exc_info=(exctype, value, tb))
    mb = QMessageBox()
    mb.setIcon(QMessageBox.Icon.Critical)
    mb.setWindowTitle("Erro inesperado")
    mb.setText(str(value))
    mb.setDetailedText("".join(traceback.format_exception(exctype, value, tb)))
    mb.exec()

class MainWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("Dona Arteira Gestão")
        self.setWindowIcon(QIcon("assets/icon.ico"))

        if STYLE_SHEET:
            self.setStyleSheet(STYLE_SHEET)
        else:
            try:
                with open("assets/style.qss", "r", encoding="utf-8") as f:
                    self.setStyleSheet(f.read())
            except Exception:
                pass

        self._build_ui()
        self.resize(1200, 800)
        self.setMinimumSize(1100, 700)
        self.statusBar().showMessage("Pronto")

        self.load_clients()

    # --------------------------
    # Construção da interface
    # --------------------------
    def _build_ui(self):
        # Ações / Menu
        self.act_new_client = QAction(QIcon("assets/user-plus.png"), "Novo Cliente", self)
        self.act_new_client.setShortcut("Ctrl+N")
        self.act_new_client.triggered.connect(self.new_client)

        self.act_edit_client = QAction(QIcon("assets/user-edit.png"), "Editar Cliente", self)
        self.act_edit_client.setShortcut("Ctrl+E")
        self.act_edit_client.triggered.connect(self._action_edit_selected_client)

        self.act_new_order = QAction(QIcon("assets/file-plus.png"), "Novo Pedido", self)
        self.act_new_order.setShortcut("Ctrl+Shift+N")
        self.act_new_order.triggered.connect(self.new_order)

        self.act_orders = QAction(QIcon("assets/list.png"), "Gerenciar Pedidos", self)
        self.act_orders.triggered.connect(self.open_orders)

        self.act_pieces = QAction(QIcon("assets/toolbox.png"), "Gerenciar Peças", self)
        self.act_pieces.triggered.connect(self.open_pieces)

        self.act_order_details = QAction(QIcon("assets/info.png"), "Detalhes do Pedido", self)
        self.act_order_details.triggered.connect(self._action_order_details)

        self.act_refresh = QAction(QIcon("assets/refresh.png"), "Atualizar (F5)", self)
        self.act_refresh.setShortcut("F5")
        self.act_refresh.triggered.connect(self.load_clients)

        menu_op = self.menuBar().addMenu("&Opções")
        menu_op.addAction(self.act_new_client)
        menu_op.addAction(self.act_edit_client)
        menu_op.addAction(self.act_new_order)
        menu_op.addSeparator()
        menu_op.addAction(self.act_orders)
        menu_op.addAction(self.act_pieces)
        menu_op.addAction(self.act_order_details)
        menu_op.addSeparator()
        menu_op.addAction(self.act_refresh)

        # Menu Aparência
        menu_ap = self.menuBar().addMenu("Aparência")
        act_light = QAction("Claro", self); act_dark = QAction("Escuro", self)
        act_light.triggered.connect(lambda: self.apply_theme("light"))
        act_dark.triggered.connect(lambda: self.apply_theme("dark"))
        menu_ap.addAction(act_light); menu_ap.addAction(act_dark)

        # Tabs
        tabs = QTabWidget(self)
        # restaura tema
        try:
            self._restore_theme_on_startup()
        except Exception:
            pass

        # --- Aba Clientes ---
        tab_clients = QWidget()
        lay_clients = QVBoxLayout(tab_clients)

        # Barra de botões
        btn_row = QHBoxLayout()
        self.bt_new_client = QPushButton("Novo Cliente")
        self.bt_new_client.clicked.connect(self.new_client)
        self.bt_edit_client = QPushButton("Editar Cliente")
        self.bt_edit_client.clicked.connect(self._action_edit_selected_client)
        self.bt_new_order = QPushButton("Novo Pedido")
        self.bt_new_order.clicked.connect(self.new_order)
        self.bt_orders = QPushButton("Gerenciar Pedidos")
        self.bt_orders.clicked.connect(self.open_orders)
        self.bt_pieces = QPushButton("Gerenciar Peças")
        self.bt_pieces.clicked.connect(self.open_pieces)
        self.bt_order_details = QPushButton("Detalhes do Pedido")
        self.bt_order_details.clicked.connect(self._action_order_details)
        self.bt_refresh = QPushButton("Atualizar")
        self.bt_refresh.clicked.connect(self.load_clients)

        for b in (self.bt_new_client, self.bt_edit_client, self.bt_new_order,
                  self.bt_orders, self.bt_pieces, self.bt_order_details, self.bt_refresh):
            btn_row.addWidget(b)
        btn_row.addStretch(1)
        lay_clients.addLayout(btn_row)

        # Árvore: Clientes (topo) e Pedidos (filhos)
        self.tree = QTreeWidget()
        self.tree.setColumnCount(4)
        self.tree.setHeaderLabels(["Nome / Pedido", "Cidade / Data", "Telefone / Valor", "Observações"])
        self.tree.itemDoubleClicked.connect(self.on_double_click)
        self.tree.currentItemChanged.connect(self._on_current_changed)
        lay_clients.addWidget(self.tree)

        tabs.addTab(tab_clients, "Clientes")

        # (Opcional) segunda aba pode ser montada em outro módulo já existente
        try:
            from da_widgets.pieces_tab import PiecesTab
            tabs.addTab(PiecesTab(self), "Peças")
        except Exception:
            pass

        self.setCentralWidget(tabs)

        # Estados iniciais dos botões
        self._update_action_states(has_client=False, has_order=False)

    # --------------------------
    # Carregar lista de clientes + pedidos
    # --------------------------
    def load_clients(self):
        self.tree.clear()
        count = 0
        try:
            with SessionLocal() as s:
                # Carrega clientes com pedidos e itens para evitar lazy loads
                stmt = (
                    select(Client)
                    .options(
                        joinedload(Client.orders).joinedload(Order.items)
                    )
                    .order_by(Client.name.asc())
                )
                for c in s.execute(stmt).unique().scalars():
                    top = QTreeWidgetItem([
                        c.name or "",
                        c.city or "",
                        getattr(c, "phone", "") or "",
                        getattr(c, "notes", "") or "",
                    ])
                    top.setData(0, Qt.ItemDataRole.UserRole, ("client", c.id))
                    self.tree.addTopLevelItem(top)
                    count += 1

                    # Pedidos filhos
                    if getattr(c, "orders", None):
                        for o in c.orders:
                            # Valor do pedido = soma (qtd * preço) dos itens
                            total = 0.0
                            if getattr(o, "items", None):
                                for it in o.items:
                                    try:
                                        q = float(getattr(it, "quantity", 0) or 0)
                                        p = float(getattr(it, "price", 0) or 0)
                                        total += q * p
                                    except Exception:
                                        pass

                            col0 = f"Pedido #{o.id}"
                            col1 = (str(getattr(o, "order_date", "")) or "")  # Data
                            col2 = f"R$ {total:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
                            col3 = getattr(o, "notes", "") or ""
                            child = QTreeWidgetItem([col0, col1, col2, col3])
                            child.setData(0, Qt.ItemDataRole.UserRole, ("order", o.id))
                            top.addChild(child)

            self.tree.expandAll()
            self.tree.resizeColumnToContents(0)
            self.statusBar().showMessage(f"{count} cliente(s) carregado(s)")
        except Exception as e:
            logging.exception("Falha ao carregar clientes")
            QMessageBox.critical(self, "Erro", f"Ocorreu um erro ao carregar a lista: {e}")

        self._update_action_states(has_client=False, has_order=False)

    # --------------------------
    # Estados dos botões/ações
    # --------------------------
    def _on_current_changed(self, cur, prev):
        has_client = False
        has_order = False
        if cur:
            kind, _id = cur.data(0, Qt.ItemDataRole.UserRole) or (None, None)
            has_client = (kind == "client")
            has_order = (kind == "order")
        self._update_action_states(has_client, has_order)

    def _update_action_states(self, has_client: bool, has_order: bool):
        # Depende de cliente
        for act in (self.act_edit_client, self.act_new_order):
            act.setEnabled(has_client)
        for btn in (self.bt_edit_client, self.bt_new_order):
            btn.setEnabled(has_client)

        # Depende de pedido
        self.act_order_details.setEnabled(has_order)
        self.bt_order_details.setEnabled(has_order)

    # --------------------------
    # Ações
    # --------------------------
    def new_client(self):
        try:
            if ClientFormDialog(self, client=None).exec():
                self.load_clients()
        except Exception as e:
            logging.exception("Falha em new_client")
            QMessageBox.critical(self, "Erro", f"Ocorreu um erro: {e}")

    def _action_edit_selected_client(self):
        item = self.tree.currentItem()
        if not item:
            return
        kind, client_id = item.data(0, Qt.ItemDataRole.UserRole) or (None, None)
        if kind != "client":
            QMessageBox.information(self, "Editar Cliente", "Selecione um cliente na lista.")
            return
        self.edit_client(client_id)

    def edit_client(self, client_id: int):
        try:
            with SessionLocal() as s:
                c = s.get(Client, client_id)
                s.expunge(c)
            if ClientFormDialog(self, client=c).exec():
                self.load_clients()
        except Exception as e:
            logging.exception("Falha em edit_client")
            QMessageBox.critical(self, "Erro", f"Ocorreu um erro: {e}")

    def new_order(self):
        # Requer cliente selecionado
        item = self.tree.currentItem()
        if not item:
            QMessageBox.information(self, "Novo Pedido", "Selecione um cliente primeiro.")
            return
        kind, client_id = item.data(0, Qt.ItemDataRole.UserRole) or (None, None)
        if kind != "client":
            QMessageBox.information(self, "Novo Pedido", "Selecione um cliente (linha superior).")
            return
        try:
            with SessionLocal() as s:
                c = s.get(Client, client_id)
                s.expunge(c)
            if OrderFormDialog(self, client=c, order=None).exec():
                self.load_clients()
        except Exception as e:
            logging.exception("Falha em new_order")
            QMessageBox.critical(self, "Erro", f"Ocorreu um erro: {e}")

    def edit_order(self, order_id: int):
        try:
            with SessionLocal() as s:
                stmt = (
                    select(Order)
                    .options(joinedload(Order.client), joinedload(Order.items))
                    .where(Order.id == order_id)
                )
                o = s.execute(stmt).unique().scalar_one_or_none()
                if not o:
                    QMessageBox.warning(self, "Editar Pedido", "Pedido não encontrado.")
                    return
                # Desanexa para usar no diálogo
                if o.client is not None:
                    s.expunge(o.client)
                s.expunge(o)
            if OrderFormDialog(self, client=o.client, order=o).exec():
                self.load_clients()
        except Exception as e:
            logging.exception("Falha em edit_order")
            QMessageBox.critical(self, "Erro", f"Ocorreu um erro: {e}")

    def _action_order_details(self):
        item = self.tree.currentItem()
        if not item:
            return
        kind, order_id = item.data(0, Qt.ItemDataRole.UserRole) or (None, None)
        if kind != "order":
            QMessageBox.information(self, "Detalhes do Pedido", "Selecione um pedido na lista.")
            return
        try:
            dlg = OrderDetailsDialog(self, order_id=order_id)
            dlg.exec()
        except Exception as e:
            logging.exception("Falha em order_details")
            QMessageBox.critical(self, "Erro", f"Ocorreu um erro: {e}")

    def on_double_click(self, item, col):
        if not item:
            return
        kind, _id = item.data(0, Qt.ItemDataRole.UserRole) or (None, None)
        if kind == "client":
            self.edit_client(_id)
        elif kind == "order":
            self.edit_order(_id)

    
    def _current_client_id(self):
        try:
            it = self.tree.currentItem()
            if not it: return None
            kind, _id = it.data(0, Qt.ItemDataRole.UserRole) or (None, None)
            if kind == "client":
                return _id
            # if selected an order line, get parent client
            if kind == "order" and it.parent():
                pkind, pid = it.parent().data(0, Qt.ItemDataRole.UserRole) or (None, None)
                if pkind == "client":
                    return pid
        except Exception:
            pass
        return None
    def open_pieces(self):
        try:
            w = PieceManagerWindow(self)
            w.exec()
        except Exception as e:
            logging.exception("Falha em open_pieces")
            QMessageBox.critical(self, "Erro", f"Ocorreu um erro: {e}")

    def open_orders(self):
        try:
            cid = self._current_client_id()
            if cid:
                w = OrderManagerWindow(self, client_id=cid)
            else:
                w = OrderManagerWindow(self)
            w.exec()
        except Exception as e:
            logging.exception("Falha em open_orders")
            QMessageBox.critical(self, "Erro", f"Ocorreu um erro: {e}")

    def apply_theme(self, mode: str):
        from PyQt6.QtWidgets import QApplication
        from PyQt6.QtGui import QPalette, QColor
        from PyQt6.QtCore import QSettings
        settings = QSettings("DonaArteira", "Gestao"); settings.setValue("theme", mode)
        app = QApplication.instance()
        if mode == "dark":
            app.setStyle("Fusion")
            pal = QPalette()
            pal.setColor(QPalette.ColorRole.Window, QColor(53,53,53))
            pal.setColor(QPalette.ColorRole.WindowText, QColor(220,220,220))
            pal.setColor(QPalette.ColorRole.Base, QColor(35,35,35))
            pal.setColor(QPalette.ColorRole.AlternateBase, QColor(53,53,53))
            pal.setColor(QPalette.ColorRole.ToolTipBase, QColor(220,220,220))
            pal.setColor(QPalette.ColorRole.ToolTipText, QColor(220,220,220))
            pal.setColor(QPalette.ColorRole.Text, QColor(220,220,220))
            pal.setColor(QPalette.ColorRole.Button, QColor(53,53,53))
            pal.setColor(QPalette.ColorRole.ButtonText, QColor(220,220,220))
            pal.setColor(QPalette.ColorRole.Highlight, QColor(142,45,197))
            pal.setColor(QPalette.ColorRole.HighlightedText, QColor(255,255,255))
            app.setPalette(pal)
        else:
            app.setPalette(QPalette())

    def _restore_theme_on_startup(self):
        from PyQt6.QtCore import QSettings
        self.apply_theme(QSettings("DonaArteira","Gestao").value("theme","dark"))
