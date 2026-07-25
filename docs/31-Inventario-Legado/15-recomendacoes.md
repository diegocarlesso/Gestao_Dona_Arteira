# 15 — Recomendações

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** chief-architect / business-analyst
> Este documento inclui **sugestões de alteração** à documentação existente (§4). Conforme a regra do Gate 01, **nada da doc existente foi alterado** — as sugestões ficam aqui para o dono aprovar.

## 1. Recomendações de migração/dados

| # | Recomendação | Prioridade |
|---|---|---|
| RC-01 | **Definir e gerar SKUs** para todo o catálogo — é a chave de casamento de tudo. ✅ **Feito em 2026-07-25** ([ADR-0022](../27-ADR/ADR-0022-modelo-de-produto-e-sku.md)): formato `DA-0001`. 📌 A parte de "reconciliar com `pieces.code` do desktop" **caiu**: o desktop nunca foi alimentado, não há código a reconciliar | 🔴 Alta |
| RC-02 | ~~**Obter o dump do banco do desktop (`dona_arteira`)**~~ 📌 **Cancelada em 2026-07-25** — não é indisponibilidade, é inexistência: o dono esclareceu que o desktop **nunca entrou em operação**. Não há dump a obter. O efeito colateral é real e permanece: atacado e balcão **não existem como dado em lugar nenhum**, e terão de ser cadastrados do zero se a empresa os praticar | ~~🔴 Alta~~ ❌ sem objeto |
| RC-03 | **Inventário físico de estoque no cutover** — não importar saldo do site (não confiável) | 🔴 Alta |
| RC-04 | **Deduplicar produtos** por título+atributos antes de carregar | 🟡 Média |
| RC-05 | **Higienizar** descrições (HTML; sem shortcodes) e títulos de pagamento (HTML) no ETL; descartar meta de page-builder | 🟡 Média |
| RC-06 | **Reclassificar categorias**: separar merchandising (`HOME SITE`), sazonal (`DIA DAS MÃES`) e material (`MDF`) da categoria temática | 🟡 Média |
| RC-07 | **Auditar `sevensi-functions`** antes do Gate 02 | 🟡 Média |
| RC-08 | **Não migrar** logs, revisões, page-builder e tabelas de plugins inativos | 🟢 Baixa |
| RC-09 | **Auditar `/uploads` e FTP** (existência/peso das imagens) antes de decidir mídia | 🟡 Média |

## 2. Recomendações de negócio/produto

| # | Recomendação |
|---|---|
| RN-01 | Levantar a **composição real dos kits/trios** (BOM) com a produção — 30% do catálogo depende disso |
| RN-02 | Avaliar **habilitar atacado no digital** — há sinais de demanda B2B batendo no site ([07](07-pedidos.md)/[13](13-oportunidades.md)) |
| RN-03 | Tratar **retirada no local como fluxo de 1ª classe** (não exceção) |
| RN-04 | Usar **relatórios de giro** para enxugar o catálogo (88% nunca vendeu online) |
| RN-05 | Definir **política LGPD** para as ~136 contas sem compra |
| RN-06 | **Congelar edições no wp-admin** após o cutover ([BR-702](../01-Regras-de-Negocio/01-registro-de-regras.md)) |

## 3. Recomendações de sequência (gates)

- O inventário **confirma** o roadmap: o **núcleo é offline** (estoque/produção/vendas balcão), o WooCommerce é **canal secundário**. Manter a integração Woo como **Gate 02**, sem superdimensioná-la.
- **Fiscal (Gate 05)** exige validação com o contador **antes** de qualquer emissão — a loja nunca calculou imposto nem emitiu NF-e.

## 4. Sugestões de alteração à documentação existente (não aplicadas)

> Apresentadas como diffs conceituais para aprovação do dono. **Não** foram editadas.

### S-01 · `docs/README.md` — adicionar a pasta 31 ao mapa
Incluir a linha:
`| 31 | [Inventario-Legado](31-Inventario-Legado/README.md) | Engenharia reversa do WooCommerce + desktop: dados reais, qualidade, riscos | Gate 01 |`

### S-02 · `docs/16-WooCommerce/01-mapeamento-de-campos.md` — fechar as "Pendências de inventário (Gate 02)"
O inventário responde todas as pendências abertas daquele doc:
- Plugin de checkout BR: **`woocommerce-extra-checkout-fields-for-brazil`** → `_billing_cpf`, `_billing_cnpj`, `_billing_persontype` (sempre "1"/PF), `_billing_neighborhood`, `_billing_number`, `_billing_cellphone`.
- Produtos variáveis: **sim** (39 variáveis / 77 variações). Cupons: **sim** (1 — `primeiracompra`). Assinaturas: **não**.
- Frete: **Correios + Melhor Envio** (Jadlog dominante). Rastreio: sem plugin ativo (EWD Order Tracking **inativo**).
- Gateways: **Mercado Pago** (PIX/cartão/boleto) + Pagaleve + parcelas.
- **Alerta crítico a destacar naquele doc:** o SKU não é apenas "às vezes vazio" — está **100% vazio**; o casamento de produto/itens precisará de estratégia própria (gerar SKU / casar por `product_id`).

### S-03 · `docs/01-Regras-de-Negocio/01-registro-de-regras.md` — evidências novas para BRs existentes
- **BR-002 (SKU único):** registrar que o **WooCommerce não tem SKU algum**; regra do desktop (`code`) não está espelhada no site → saneamento obrigatório.
- **BR-006 / BR-301 (PF/PJ, atacado):** registrar evidência de que o **site é 100% PF**; atacado/PJ é **exclusivamente offline** — o critério de atacado deve ser levantado no desktop/entrevista, não no Woo.
- **BR-204 (estoque publicado):** registrar que o site **hoje não controla quantidade** (708/716) — a fórmula disponível−buffer nasce com o ERP, sem base histórica.
- **BR-308 (pagamento):** confirmar formas reais: **PIX (46%), Cartão (48%), Boleto (6%)** via Mercado Pago; incluir "PIX parcelado (Pagaleve)".
- **BR-306 (retirada/envio):** registrar que **retirada é ~38%** dos pedidos (peso alto, não exceção).

### S-04 · `docs/30-Dominio-da-Dona-Arteira/README.md` — seção "Confirmado"
Promover de hipótese a **confirmado pelos dados**: sazonalidade de maio (Dia das Mães); linhas de produto (Arte Sacra, Budas, Ganeshas, Incensários, Elefantes, Africanas, Orixás); existência de **kits/trios** e de **revenda (incensos) e MDF**; concentração geográfica em RS; e que **o online é canal secundário**.

### S-05 · `docs/29-Glossario/README.md` — termos novos
Sugerir inclusão dos termos extraídos em [17-glossario-extraido.md](17-glossario-extraido.md) (ex.: Incensário cascata/vareta, Trio, Kit, Orixá, Escapulário, "cores de acabamento", Retirada no local).

## 5. Próximos passos

1. Dono revisa e aprova as sugestões §4.
2. `technical-writer` aplica as aprovadas (nunca automaticamente).
3. Perguntas de [98](98-perguntas-para-o-negocio.md) entram na pauta das entrevistas (pré-requisito dos Gates 02–03).
