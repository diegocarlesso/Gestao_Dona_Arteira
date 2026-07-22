PALETTE = {
    "bg": "#111111", "panel": "#1f1f1f", "text": "#f4f4f4", "muted": "#b7b7b7",
    "pink": "#ff69b4", "green": "#49c769", "blue": "#35a4ff", "yellow": "#ffcc33",
    "danger": "#ff5a5a", "ok": "#55dd77", "accent": "#ff69b4",
}
STYLE_SHEET = f"""
QWidget {{ background: {PALETTE['bg']}; color: {PALETTE['text']}; font-size: 14px; }}
QLineEdit, QComboBox, QTextEdit, QDateEdit, QSpinBox, QDoubleSpinBox {{
    background: {PALETTE['panel']}; padding: 6px; border: 1px solid #333; border-radius: 8px;
}}
QPushButton {{ background: {PALETTE['accent']}; color: #000; border-radius: 10px; padding: 6px 12px; font-weight: 600; }}
QPushButton:disabled {{ background: #555; color:#999; }}
QTreeWidget, QTableWidget {{ background: {PALETTE['panel']}; border: 1px solid #333; border-radius: 8px; }}
QHeaderView::section {{ background: #2b2b2b; padding: 6px; border: none; }}
QToolButton {{ background: #2b2b2b; border: 1px solid #333; border-radius: 8px; padding: 6px; }}
"""
