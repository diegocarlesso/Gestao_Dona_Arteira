# 06 — Clientes

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** migration-specialist / sales-specialist
> **Regras relacionadas:** BR-001 (CPF/CNPJ), BR-006 (PF/PJ), BR-301 (atacado), BR-706 (dedupe migração)

## 1. Objetivo

Inventariar a base de clientes do WooCommerce — quantidade, natureza (PF/PJ), geografia, completude cadastral e duplicidade — para o plano de deduplicação e migração de clientes ([BR-706](../01-Regras-de-Negocio/01-registro-de-regras.md), pasta 17).

## 2. Quantidades

| Métrica | Valor |
|---|---:|
| Usuários WordPress totais | 200 |
| — papel `customer` | 198 |
| — papel `administrator` | 2 |
| Registros em `wc_customer_lookup` | 191 |
| Contatos FunnelKit (`bwf_wc_customers`) | 57 |
| **Compradores reais** (com pedido) | **62** |
| Registrados que **nunca compraram** | ~136 |

**Leitura:** há **198 contas de cliente**, mas apenas **62 compraram** de fato. ~136 contas são "vazias" (cadastro sem compra — provável cadastro de checkout abandonado, newsletter ou importações). A base **efetiva** é pequena.

## 3. Natureza: PF vs PJ

| Indicador | Valor |
|---|---:|
| Pedidos com `persontype = 1` (Pessoa Física) | **85 (100%)** |
| Pedidos com `persontype = 2` (Pessoa Jurídica) | **0** |
| Pedidos com CPF preenchido | 85 (100%) |
| Pedidos com CNPJ preenchido | 0 |

**Todos os clientes do site são Pessoa Física.** Não há **nenhum** cliente PJ/atacado no WooCommerce. Isso é decisivo para o desenho de Vendas:

- O **canal online é 100% varejo/PF**. O **atacado/PJ** ([BR-006](../01-Regras-de-Negocio/01-registro-de-regras.md), [BR-301](../01-Regras-de-Negocio/01-registro-de-regras.md)) — que o desktop suporta com `price_wholesale` e `cpf_cnpj` — **acontece inteiramente fora do site**, provavelmente no desktop/balcão.
- Confirma a hipótese da pasta 30: "o legado tem preço de atacado e CNPJ, indicando venda a lojistas" — os lojistas **não** estão no site.

## 4. Documentos e contato (completude)

- **CPF capturado em 100% dos pedidos** (plugin *Extra Checkout Fields for Brazil* — ver [10](10-plugins.md)/[11](11-metadados.md)).
- **4 pedidos sem telefone algum** (nem `_billing_phone` nem `_billing_cellphone`).
- No cadastro de usuário (`usermeta`): ~63 têm `billing_cpf`, 47 `billing_phone`, 27 `billing_cellphone` — consistente com os ~62 compradores reais (os demais ~136 registrados não têm perfil de cobrança).

## 5. Geografia

Distribuição por UF (nos 85 pedidos):

| UF | Pedidos | % |
|---|---:|---:|
| **RS** | 52 | 61% |
| SP | 15 | 18% |
| RJ | 7 | 8% |
| PR | 4 | 5% |
| PE | 2 | 2% |
| SC | 2 | 2% |
| MG | 2 | 2% |
| DF | 1 | 1% |

**RS domina (61%)** — coerente com a loja sediada em **Jacutinga/RS** e com a alta taxa de retirada no local ([09](09-formas-entrega.md)). Alcance nacional existe, mas concentra-se no Sul/Sudeste.

## 6. Comportamento de compra

| Nº de pedidos | Clientes |
|---|---:|
| 5 pedidos | 1 |
| 4 pedidos | 2 |
| 3 pedidos | 3 |
| 2 pedidos | 7 |
| 1 pedido | 49 |

- **62 compradores**; **13 (21%)** compraram mais de uma vez; o mais fiel fez **5 compras**.
- Recorrência baixa em volume absoluto, mas existente — há clientes que voltam.

## 7. Duplicidade (qualidade para dedupe)

| Verificação | Resultado |
|---|---:|
| CPFs distintos entre compradores | 62 |
| E-mails distintos entre compradores | 62 |
| Duplicidade CPF↔e-mail | **0** 🟢 |

Entre os compradores reais, **CPF e e-mail batem 1:1** — base **limpa para dedupe** ([BR-706](../01-Regras-de-Negocio/01-registro-de-regras.md)). O risco de duplicidade **não** está dentro do site, e sim **entre site e desktop** (mesmo cliente nas duas fontes) — que **não pôde ser avaliado** porque o banco do desktop não foi fornecido. Ver [14](14-riscos.md)/[98](98-perguntas-para-o-negocio.md).

## 8. Clientes "inativos"

Não há flag de inatividade. Na prática, ~136 contas nunca geraram pedido (candidatas a "inativas"/lixo de cadastro). A LGPD ([25-Segurança](../25-Seguranca/README.md)) recomenda avaliar a **retenção** desses cadastros sem compra na migração.

## 9. Impacto na migração

- Migrar como **PF/varejo**; a dimensão **atacado/PJ virá do desktop**, não do site.
- Dedupe por **e-mail (nº1)** e **CPF (nº2)** ([16-WooCommerce/01](../16-WooCommerce/01-mapeamento-de-campos.md)); atenção à **sobreposição site×desktop**.
- Decidir política para as ~136 contas sem compra (migrar? arquivar? LGPD).
