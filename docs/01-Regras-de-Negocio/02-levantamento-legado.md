# Levantamento do Sistema Legado (Desktop Python)

> **Status:** Aprovado · **Última atualização:** 2026-07-27 · **Responsável:** business-analyst
> **Fonte:** `Dona_Arteira_Gestao_desktop/dagestao/` — leitura de referência, sem conversão automática

## 1. Objetivo

Registrar o que o sistema desktop faz hoje, que dados guarda e que regras evidencia — insumo para o registro de regras (BR-xxx) e para o modelo de dados do ERP. **O código legado não será evoluído nem convertido.**

## 2. Arquitetura do legado (contexto)

- Python + SQLAlchemy + interface desktop (widgets próprios).
- Banco **MySQL** (`dona_arteira`) — possivelmente o mesmo servidor Hostinger do site.
- Imagens de peças enviadas por **FTP** para a hospedagem (`piece_images.ftp_path`).
- Sem autenticação multiusuário, sem auditoria, sem controle de produção/financeiro/fiscal.

## 3. Modelo de dados do legado

| Tabela | Campos-chave | Observações para o ERP |
|---|---|---|
| `clients` | name, phone, endereço completo (street, number, neighborhood, city, state, cep), `cpf_cnpj` **unique/not null**, notes | Endereço único por cliente → ERP terá múltiplos endereços; CPF/CNPJ validado por DV (BR-001) |
| `packages` | name unique, height/width/depth (cm), weight (g) | Catálogo de embalagens dimensionadas → base do cálculo de frete (BR-004) |
| `pieces` | `code` unique (SKU), description, `price_retail`, `price_wholesale`, dimensões, peso, `in_stock` (int), `package_id`, imagens | Duas listas de preço (BR-003); estoque como contador simples → ERP substitui por ledger (ADR-0008) |
| `piece_images` | `ftp_path` | Mídia em FTP → estratégia de mídia definida no ADR-0017 |
| `orders` | client, `order_date`, `delivery_date` (nullable), `delivery_method` (Retirada/Entrega), `payment_method` (Dinheiro/PIX/Cartão/Boleto/Outro), `payment_value`, notes | `delivery_date` sugere **encomendas** (BR-307); pagamento é um valor único no pedido → ERP separa títulos (BR-501) |
| `order_items` | piece, quantity, **price** (snapshot), description | Preço congelado no item (BR-302); descrição livre por item sugere personalizações |

## 4. Regras evidenciadas (mapeadas para BRs)

- Validação matemática de CPF e CNPJ (`validators.py`) → BR-001.
- Unicidade de SKU (`pieces.code`) → BR-002.
- Varejo × atacado por peça → BR-003/BR-301.
- Embalagem padrão por peça com dimensões/peso → BR-004.
- Preço registrado no item do pedido, não referenciado da tabela → BR-302.
- Pedido com data de entrega futura e método retirada/entrega → BR-306/BR-307.

## 5. Lacunas do legado (o que o ERP acrescenta)

Produção (pintura) · movimentos de estoque · compras · financeiro (a pagar/receber) · fiscal/NF-e · multiusuário com permissões · auditoria · integração WooCommerce (hoje os dois sistemas não conversam!) · relatórios.

> O legado (e a documentação antiga) modelava **fundição/moldes** — premissa falsa: a Dona Arteira compra peças cruas e só pinta. Corrigido no [ADR-0023](../27-ADR/ADR-0023-producao-e-pintura-nao-fundicao.md).

## 6. Pendências de levantamento

- [ ] Obter dump/acesso ao banco MySQL do legado para contagem de registros (quantos clientes/peças/pedidos) — dimensiona a migração.
- [ ] Descobrir se o `in_stock` do legado ou o estoque do WooCommerce é o mais confiável — decide a fonte do estoque inicial (recomendação da pasta 17: **inventário físico** no cutover).
- [ ] Verificar se há dados no legado que não existem no Woo (clientes de balcão/atacado!) — provável fonte adicional da migração.
- [ ] Confirmar duplicidade de clientes entre legado e Woo (mesmo CPF/e-mail) para o plano de deduplicação.

## 7. Riscos

| Risco | Mitigação |
|---|---|
| Tratar o legado como especificação (ele tem bugs e simplificações) | Toda regra extraída nasce como Hipótese; validação com a operação |
| Ignorar clientes/pedidos que só existem no desktop | Migração (pasta 17) trata **duas fontes**: Woo + banco do legado |
