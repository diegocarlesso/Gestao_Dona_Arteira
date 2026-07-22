# Dona Arteira (PyQt6 + MySQL + FTP)
App de gestão (clientes, pedidos e peças) com tema nas cores do logo.
- CPF/CNPJ validados em tempo real (✔/✖) e bloqueiam salvar se inválidos.
- Telefone com máscara (XX)XXXXX-XXXX.
- Imagens das peças enviadas ao FTP e miniaturas exibidas.
- Sem importador: cadastro direto pelo app.

## Rodando
1. `pip install -r requirements.txt`
2. Configure `.env` (já incluso).
3. `python run.py`

Tabelas são criadas automaticamente; embalagens padrão são inseridas se vazias.
