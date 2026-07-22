# 29 — Glossário (Linguagem Ubíqua)

> **Status:** Em revisão (cresce continuamente) · **Última atualização:** 2026-07-03 · **Responsável:** technical-writer
> Vocabulário oficial do projeto: negócio, técnica e fiscal. Termo usado em tela, doc ou código com outro sentido é bug de comunicação. Termo de código (inglês) ao lado do termo de negócio.

## Domínio Dona Arteira

| Termo | Código | Definição |
|---|---|---|
| Peça | `Product (kind=finished_good)` | produto acabado de gesso, pintado à mão, pronto para venda |
| Matéria-prima (MP) | `Product (kind=raw_material)` | gesso em pó, tintas, vernizes, essências etc. |
| Embalagem | `Package` / `kind=packaging` | caixa/proteção padrão de uma peça, com dimensões e peso (base do frete) |
| Molde | `Mold` | forma (geralmente silicone/borracha) usada na fundição; ativo com vida útil em usos |
| Fundição | `casting` | despejo da mistura de gesso no molde |
| Secagem/cura | `drying` | período (dias) em que a peça perde umidade antes de pintar |
| Pintura | `painting` | pintura manual artesanal da peça |
| Acabamento | `finishing` | verniz, correções finais |
| CQ / Controle de qualidade | `qc` | inspeção final; aprova, manda retrabalhar ou registra perda |
| Quebra / perda | `loss` | peça inutilizada (frágil por natureza); registrada por etapa e motivo |
| Ficha técnica | `BOM (bill of materials)` | receita da peça: quantidades de MP + % de perda esperada |
| Encomenda | `make-to-order` | venda de item sem estoque com data prometida, que puxa produção |
| Atacado / varejo | `wholesale / retail` | duas listas de preço (herdadas do legado) |
| Ateliê | `location` | local de produção/estoque principal |

## Operação e ERP

| Termo | Código | Definição |
|---|---|---|
| OP — Ordem de produção | `ProductionOrder` | ordem de fabricar N unidades de uma peça, com etapas e apontamentos |
| Movimento de estoque | `InventoryMovement` | registro imutável de entrada/saída; única forma de alterar saldo |
| Saldo físico / reservado / disponível | `on_hand / reserved / available` | disponível = físico − reservado |
| Buffer de canal | `channel buffer` | margem subtraída do disponível publicado no site (anti-oversell) |
| Reserva | `StockReservation` | trava de quantidade para um pedido confirmado |
| Contagem / inventário | `StockCount` | conferência física que gera ajustes auditados |
| Custo médio (móvel) | `avg_cost` | custo recalculado a cada entrada; método de custeio adotado |
| WIP | — | work in progress: peças entre fundição e CQ |
| Separação / embalagem / expedição | `picking / packing / shipping` | etapas do fulfillment |
| Título | `Receivable / Payable` | conta a receber/pagar, com baixas |
| Baixa | `Settlement` | quitação (total/parcial) de um título em uma conta |
| Cutover | — | momento em que o ERP vira mestre e a sync com o Woo é ligada |
| SSOT | — | Single Source of Truth: o ERP, após o cutover |

## Fiscal

| Termo | Definição |
|---|---|
| NF-e (mod. 55) | nota fiscal eletrônica de produto; autorizada pela SEFAZ |
| DANFE | representação impressa da NF-e (re-gerável do XML) |
| Chave de acesso | identificador de 44 dígitos da NF-e |
| SEFAZ | secretaria da fazenda; autoriza/rejeita notas |
| Certificado A1 | certificado digital em arquivo (validade 1 ano) que assina o XML |
| CFOP | código fiscal da operação (ex.: 5101 venda de produção própria dentro da UF) |
| NCM | classificação da mercadoria (gesso decorado: hipótese 6809.90.00 — validar) |
| CEST | código de substituição tributária (verificar aplicabilidade) |
| CSOSN | código de situação tributária do Simples Nacional (hipótese: 102) |
| Simples Nacional | regime tributário presumido da empresa (validar com contador) |
| CC-e | carta de correção eletrônica (erros não essenciais) |
| Inutilização | comunicação à SEFAZ de números de nota pulados |
| Contingência SVC | emissão pelos servidores virtuais quando a SEFAZ da UF cai |
| IBS / CBS | tributos da reforma tributária em transição desde 2026 — impactam layout da NF-e |
| Manifestação do destinatário | confirmação de NF-e recebidas de fornecedores (evolução) |

## Técnica

| Termo | Definição |
|---|---|
| ADR | Architecture Decision Record — registro imutável de decisão (pasta 27) |
| BR-xxx | regra de negócio registrada (pasta 01) |
| Bounded context | fronteira de modelo do domínio (pasta 02) |
| ACL (anticorrupção) | camada que traduz sistemas externos para o modelo interno |
| Ledger | registro contábil append-only (padrão do estoque) |
| Idempotência | executar 2× tem o efeito de 1× — obrigatório em jobs/webhooks/migração |
| Webhook | chamada HTTP que um sistema faz ao outro quando algo acontece |
| Reconciliação | comparação periódica ERP × externo que corrige o que os webhooks perderam |
| ULID | identificador público ordenável usado na API |
| Staging (dados) | tabelas `stg_*` intermediárias da migração |
| Staging (ambiente) | ambiente de validação pré-produção |
| Expand/contract | evolução de esquema em duas etapas sem quebrar o release corrente |
