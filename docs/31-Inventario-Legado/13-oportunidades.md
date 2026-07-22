# 13 — Oportunidades

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** business-analyst / chief-architect

## 1. Objetivo

Registrar oportunidades de negócio e de produto descobertas na engenharia reversa — o que o ERP pode **destravar** que hoje o ambiente atual não entrega.

## 2. Oportunidades

### O-01 · Unificar o multicanal (hoje fragmentado)
O site testou **Shopee, Lazada, Facebook, Google Shopping** e vários funis, sem consolidação ([10](10-plugins.md)). O ERP como **SSOT** ([ADR-0006](../27-ADR/ADR-0006-erp-ssot.md)) pode publicar um catálogo único e organizado para vários canais, com estoque e preço coerentes — transformando experimentação dispersa em **operação multicanal controlada**.

### O-02 · Trazer o atacado para o digital
O canal online é **100% PF/varejo** ([06](06-clientes.md)), mas o desktop tem **preço de atacado e CNPJ**, e os **pedidos cancelados de alto valor** (até R$ 3.880, [07](07-pedidos.md)) sugerem **demanda de atacado batendo no site sem lugar para acontecer**. O ERP com preço varejo/atacado ([BR-003/301](../01-Regras-de-Negocio/01-registro-de-regras.md)) e um fluxo B2B pode **capturar essa demanda reprimida**.

### O-03 · Controle real de estoque (inexistente hoje)
708/716 produtos **não controlam quantidade** ([02](02-produtos.md)); o site só liga/desliga "esgotado" na mão. O **ledger de estoque** ([ADR-0008](../27-ADR/ADR-0008-ledger-estoque.md)) + publicação com buffer ([BR-204](../01-Regras-de-Negocio/01-registro-de-regras.md)) elimina oversell e dá visão de disponibilidade que **não existe** — melhora venda e produção.

### O-04 · Estruturar kits/trios como produtos com ficha técnica (BOM)
**213 produtos (30%) são kits/trios** ([02](02-produtos.md)) e são **campeões de venda** ([07](07-pedidos.md)), mas sua composição é texto de vitrine ([04](04-atributos.md)). Estruturar o **BOM** (componentes + quantidades) permite planejar produção, custear corretamente e vender kit sem furar estoque dos componentes.

### O-05 · Padronizar SKU e catálogo
A ausência total de SKU ([02](02-produtos.md)) e as duplicatas de título são um problema — mas também a chance de **nascer com um catálogo limpo e codificado** no ERP, base para relatórios, integrações e fiscal.

### O-06 · Aproveitar a força da retirada local
**~38% dos pedidos são retirada** ([09](09-formas-entrega.md)) e RS é 61% da base. Há oportunidade de **fluxo de balcão/retirada de primeira classe** no ERP (não como exceção), com reserva, aviso "pronto para retirar" e integração com a venda presencial.

### O-07 · Recuperar clientes e reativar base
FunnelKit/cart-abandonment já roda; há **~136 contas sem compra** e 13 clientes recorrentes. Com dados unificados, o ERP habilita **reativação e pós-venda** dirigidos (respeitando LGPD).

### O-08 · Sazonalidade planejável
Picos claros em **maio (Dia das Mães)** e datas comemorativas ([07](07-pedidos.md)). Com produção puxada por previsão, o ERP permite **produzir antes do pico** (o gesso tem lead time de secagem) em vez de perder venda por ruptura.

### O-09 · Inteligência de sortimento
**88% dos produtos nunca venderam online** ([02](02-produtos.md)). Relatórios de giro ([20-Relatórios](../20-Relatorios/README.md)) podem orientar **enxugar o catálogo**, focar produção nos campeões (kits/incensários/trios) e decidir descontinuações.

### O-10 · Base fiscal a partir do zero, sem dívida
Como o site **não calcula imposto** ([01](01-visao-geral.md)) e não emite NF-e, o ERP pode implantar o fiscal **corretamente desde a origem** (NCM por material — gesso vs MDF vs revenda), sem herdar configuração errada.

## 3. Priorização sugerida

| Oportunidade | Esforço | Impacto | Momento |
|---|---|---|---|
| O-03 Estoque real | Médio | Alto | Gate 01/02 (núcleo) |
| O-05 SKU/catálogo | Médio | Alto | Migração (Gate 01) |
| O-04 BOM de kits | Alto | Alto | Gate 03 (Produção) |
| O-02 Atacado digital | Médio | Alto | Gate 02+ (Vendas) |
| O-06 Retirada de 1ª classe | Baixo | Médio | Gate 02 |
| O-01 Multicanal | Alto | Médio | Pós-núcleo |
| O-08/O-09 Sazonalidade/giro | Baixo | Médio | Gate 06 (relatórios) |
