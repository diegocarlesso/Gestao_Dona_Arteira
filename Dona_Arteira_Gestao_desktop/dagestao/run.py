import sys, os
sys.path.insert(0, os.path.dirname(__file__))
import sys
from PyQt6.QtWidgets import QApplication
from main_window import MainWindow
def main():
    app = QApplication(sys.argv); win = MainWindow(); win.resize(1100, 700); win.show(); sys.exit(app.exec())
if __name__ == "__main__":
    main()
