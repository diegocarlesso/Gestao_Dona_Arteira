# Registro de Regras de Negócio

> **Status:** Em revisão · **Última atualização:** 2026-08-10 · **Responsável:** business-analyst
> Legenda de status: 💡 Hipótese · ✅ Validada · 🔧 Implementada · ❌ Revogada

Regras nascem 💡 e só viram ✅ com validação nominal (quem validou + data). Regras extraídas do sistema legado indicam origem `legado`.

## BR-0xx — Gerais e cadastros

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-001 | CPF/CNPJ de cliente é obrigatório para faturamento (NF-e), validado por dígito verificador, e único no cadastro | legado (`validators.py`, unique em `clients.cpf_cnpj`) | ✅ **decidida em 2026-07-27**: cliente de balcão **pode** ser cadastrado sem documento. A obrigatoriedade é do faturamento, não da existência — exigi-la no cadastro empurraria a operação a inventar número. Sem documento o cliente existe e a emissão fica bloqueada; a listagem mostra a pendência antes da venda. Validação em `Support\Documento`, único entre os não nulos. Ver [pasta 10](../10-Vendas/README.md) |
| BR-002 | Todo produto (peça) tem código (SKU) único e imutável após criação | **esquema** do desktop (`pieces.code` unique) — nunca operado | ✅ **decidido** ([ADR-0022](../27-ADR/ADR-0022-modelo-de-produto-e-sku.md)): formato `DA-0001`, sequencial e **sem significado embutido**, gerado pelo ERP. Nem o Woo (0/716) nem o desktop (nunca alimentado) têm SKU: **o ERP é a primeira origem de código da empresa** — ninguém tem código decorado a preservar |
| BR-003 | Toda peça possui dois preços de lista: varejo e atacado | **confirmada pelo dono** em 2026-07-25 (a empresa vende nos dois canais); o esquema do desktop já a antecipava, mas nunca operou | ✅ **regra confirmada, dado inexistente.** A empresa pratica atacado — logo a regra vale. Mas o preço de atacado **nunca foi registrado em sistema nenhum**: o WooCommerce só tem varejo e o desktop nunca foi alimentado. A lista de atacado precisa ser **levantada com quem vende** e carregada. Ver [pasta 32 §3.5](../32-Catalogo/README.md); critério de elegibilidade em BR-301 |
| BR-004 | Toda peça vendável tem embalagem padrão associada, com dimensões e peso — insumo do cálculo de frete | legado (`packages` + `pieces.package_id`) | 💡 |
| BR-005 | Peça tem dimensões (A×L×P cm) e peso (g) próprios, distintos dos da embalagem | legado | 💡 |
| BR-006 | Cliente pode ser PF (CPF) ou PJ (CNPJ); PJ com IE habilita operações de atacado/revenda | derivada | 💡 |
| BR-007 | Categorias de produto formam árvore (categoria → subcategoria), espelhada do WooCommerce na migração | Woo | 💡 |
| BR-008 | Cadastros nunca são excluídos fisicamente se possuem movimento; são inativados (soft delete/arquivamento) | decisão nova | ✅ arquitetura (ADR-0002) |
| BR-009 | Cada acabamento (cor) e tamanho de uma peça é um **produto próprio, com SKU e saldo próprios** — não é atributo de um produto-pai | decisão nova | ✅ **decidido** ([ADR-0022](../27-ADR/ADR-0022-modelo-de-produto-e-sku.md)): a cor é acabamento de pintura manual, produzido e estocado separadamente. Regra nova porque o legado não a expressava: o WooCommerce usava variações e o desktop não modelava variação alguma |

## BR-1xx — Produção

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-101 | Toda produção (pintura) ocorre via Ordem de Produção (OP); não existe entrada de peça acabada sem OP (exceto ajuste de inventário auditado) | decisão nova ([ADR-0023](../27-ADR/ADR-0023-producao-e-pintura-nao-fundicao.md)) | 💡 |
| BR-102 | A OP percorre etapas: **Pintura → Acabamento → Controle de Qualidade**, configuráveis por peça. Não há fundição nem secagem como etapa de produção — a secagem é quarentena de recebimento (BR-404) | decisão nova ([ADR-0023](../27-ADR/ADR-0023-producao-e-pintura-nao-fundicao.md)) | 💡 |
| BR-103 | Consumo de **peça crua + tinta/verniz** é apontado na OP e baixa estoque via movimento `production_input` | decisão nova ([ADR-0023](../27-ADR/ADR-0023-producao-e-pintura-nao-fundicao.md)) | 💡 |
| BR-104 | Perdas (quebras) são registradas por etapa — **pintura/acabamento/CQ** — com motivo; peça reprovada no CQ vira perda ou retrabalho. Quebra **antes** da OP (recebimento/secagem) é perda de estoque (BR-405), não de produção | entrevista pendente | 💡 |
| BR-105 | ~~Fundição consome usos do molde; molde tem vida útil estimada e alerta de reposição~~ — **não há moldes nem fundição** ([ADR-0023](../27-ADR/ADR-0023-producao-e-pintura-nao-fundicao.md)) | entrevista pendente | ❌ Revogada (2026-07-27) |
| BR-106 | ~~Secagem tem lead time mínimo por peça (dias); peças em secagem são WIP indisponível para venda~~ — secagem **não é etapa de produção**; virou quarentena de recebimento (BR-109/BR-404) ([ADR-0024](../27-ADR/ADR-0024-quarentena-de-secagem.md)) | entrevista pendente | ❌ Revogada como regra de produção (2026-07-27) |
| BR-107 | Só peças aprovadas no CQ entram no estoque de peça acabada | decisão nova | 💡 |
| BR-108 | Custo da peça acabada por **custeio ABC**: peça crua (custo médio) + insumos (tinta/verniz) + **mão de obra de pintura (minutos de bancada × custo/hora)** + overhead rateado; fase 3 pode usar custo/hora padrão configurável e evoluir para apontamento de tempo por peça/lote | decisão nova ([ADR-0023](../27-ADR/ADR-0023-producao-e-pintura-nao-fundicao.md)) | 💡 |
| BR-109 | OP de pintura só consome peça crua **seca e liberada** (localização Ateliê); peça crua em `quarantine` é indisponível para produção | decisão nova ([ADR-0024](../27-ADR/ADR-0024-quarentena-de-secagem.md)) | 💡 |

## BR-2xx — Estoque

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-201 | Saldo de estoque nunca fica negativo; movimento que resultaria em negativo é rejeitado (exceção: ajuste de inventário com permissão específica) | decisão nova | ✅ arquitetura (ADR-0008) |
| BR-202 | Todo movimento de estoque é imutável e referencia sua origem (OP, pedido, compra, ajuste); estorno é um contra-movimento | decisão nova | ✅ arquitetura (ADR-0008) |
| BR-203 | Pedido confirmado **reserva** estoque; expedição baixa; cancelamento libera a reserva | decisão nova | 💡 |
| BR-204 | Estoque publicado no WooCommerce = disponível (físico − reservado) − buffer de segurança configurável por produto | decisão nova | 💡 definir buffer padrão |
| BR-205 | Ajuste de inventário exige contagem registrada, motivo e aprovação de quem NÃO fez a contagem (segregação) | decisão nova | 💡 |
| BR-206 | Custeio por média móvel: cada entrada por compra/produção recalcula o custo médio do item | decisão nova | 💡 validar com contador |
| BR-207 | Estoque é segmentado por tipo: **peça crua**, **peça acabada**, matéria-prima, em processo (WIP — OPs abertas), embalagem, revenda | decisão nova | 💡 |

## BR-3xx — Vendas

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-301 | Preço atacado aplica-se a clientes marcados como atacadistas e/ou pedidos acima de quantidade mínima — critério a definir | legado (dois preços) | 💡 **entrevista obrigatória** |
| BR-302 | Preço do item é congelado no pedido (snapshot); mudança de tabela não altera pedidos existentes | legado (`order_items.price`) | ✅ padrão de mercado |
| BR-303 | Pedido segue máquina de estados única independente do canal: Rascunho → Confirmado → Pago → Em separação → Embalado → Expedido → Entregue (+ Cancelado/Devolvido) | decisão nova | 💡 |
| BR-304 | Pedidos do WooCommerce entram no ERP com status mapeado do status Woo e **não são editáveis** no ERP exceto avanço de fulfillment | decisão nova | ✅ **entrada implementada (corte 4, 2026-07-28):** casados por id do Woo, importados como Confirmado (reserva) ou rascunho+pendência; `SaveOrderService` só edita rascunho, então pedido do site (confirmado) não se edita. Saída de status/rastreio no corte 3 |
| BR-305 | Desconto manual acima de limite configurável exige aprovação de alçada superior | decisão nova | 💡 definir limite |
| BR-306 | Entrega pode ser Retirada ou Envio; envio exige endereço validado e cálculo de frete | legado (`DeliveryMethod`) | 💡 |
| BR-307 | Encomenda (produto sem estoque) é permitida e gera demanda de produção com data prometida | legado (`delivery_date`) | 💡 confirmar fluxo real |
| BR-308 | Formas de pagamento aceitas: Dinheiro, PIX, Cartão, Boleto, Outro — parametrizável | legado (`PaymentMethod`) | 💡 |
| BR-309 | Expedição só ocorre com NF-e autorizada quando a operação exigir documento fiscal (ver BR-601) | decisão nova | 💡 validar exceções com contador |
| BR-310 | Cliente com e-mail cadastrado recebe e-mail transacional nos marcos do pedido: confirmação (BR-303 Confirmado) e envio (BR-303 Expedido, com rastreio se houver). Sem e-mail cadastrado, ou pedido de balcão sem cliente, não é falha — é ausência de destinatário | decisão nova | 🔧 **implementado em 2026-08-06:** ver [docs/15-Integracoes/01-email-transacional.md](../15-Integracoes/01-email-transacional.md) |
| BR-311 | Pedido cancelado pode ser excluído (soft delete), mas só se nunca teve NF-e emitida (`nfe_status` nulo) — nota fiscal é documento legal e não pode desaparecer das listagens | decisão do dono (2026-08-11) | ✅ **implementado em 2026-08-11** |

## BR-4xx — Compras

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-401 | Entrada de **peça crua, MP, insumo ou revenda** em estoque só via recebimento de pedido de compra (com conferência de quantidade) ou ajuste auditado; peça crua entra em **quarentena** (BR-404) | decisão nova | 💡 |
| BR-402 | Recebimento atualiza custo médio do item e gera conta a pagar | decisão nova | 💡 **gatilho faseado (decisão da diretoria, 2026-08-10):** na Fase 1 (P0, sem Estoque), quem gera a conta a pagar é o **PC confirmado/lançado** — não há recebimento físico nem custo médio ainda (ver [pasta 11 nota de fase](../11-Compras/README.md#1-objetivo)). O texto original (recebimento → custo médio + conta a pagar) volta a valer na Fase 2, quando o Estoque entrar |
| BR-403 | Divergência entre pedido e recebimento (falta/sobra/avaria) é registrada e não bloqueia entrada parcial | decisão nova | 💡 |
| BR-404 | Recebimento de peça crua entra na localização `quarantine`; não fica liberada para pintar até a liberação da secagem (default `received_at + drying_days`; manual — padrão — ou por data) | decisão nova ([ADR-0024](../27-ADR/ADR-0024-quarentena-de-secagem.md)) | 💡 |
| BR-405 | Cada recebimento é um lote (fornecedor + data); a taxa de quebra por fornecedor/lote é medida pelas perdas (`loss`) que referenciam o recebimento | decisão nova ([ADR-0024](../27-ADR/ADR-0024-quarentena-de-secagem.md)) | 💡 |
| BR-406 | O pedido de compra só oferece produtos compráveis de fornecedor (`kind` ≠ peça acabada): matéria-prima, embalagem, insumo, revenda. Peça acabada é pintura interna sobre peça crua comprada (ADR-0023) — nenhum fornecedor vende por cor, então a busca de item do PC não pode oferecer o SKU já pintado | decisão nova ([ADR-0022](../27-ADR/ADR-0022-modelo-de-produto-e-sku.md), [ADR-0023](../27-ADR/ADR-0023-producao-e-pintura-nao-fundicao.md)) | ✅ **implementado em 2026-08-11** |

## BR-5xx — Financeiro

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-501 | Faturamento de pedido gera título(s) a receber conforme condição de pagamento; recebimento de compra gera título a pagar | decisão nova | 💡 |
| BR-502 | Baixa de título registra conta financeira, data e valor; baixa parcial é permitida | decisão nova | 💡 |
| BR-503 | Todo título tem categoria do plano de contas gerencial (árvore simples) | decisão nova | 💡 definir plano inicial com o dono |
| BR-504 | Estorno financeiro nunca apaga o título original — gera contrapartida auditada | decisão nova | ✅ princípio de auditoria |

### Cobrança (boleto / PIX com vencimento) — [ADR-0018](../27-ADR/ADR-0018-cobranca-boleto.md), [doc 12/01](../12-Financeiro/01-cobranca-e-boletos.md)

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-505 | Cobrança (boleto ou PIX com vencimento) só é emitida a partir de um título a receber existente; não existe cobrança avulsa | decisão nova | 💡 |
| BR-506 | Cobrança registrada no banco é imutável: alterar valor ou vencimento exige cancelar a cobrança e emitir outra — o título permanece o mesmo | regra bancária | 💡 |
| BR-507 | Liquidação informada pelo provedor gera baixa **idempotente** do título, com chave no ID da cobrança/evento no provedor; evento reprocessado nunca duplica baixa | decisão nova | 💡 |
| BR-508 | Multa, juros e desconto são parametrizados por perfil de cobrança versionado por vigência, nunca digitados por cobrança | decisão nova | 💡 confirmar percentuais com o contador (G-03) |
| BR-509 | Pagamento a menor gera baixa **parcial** e mantém o saldo aberto; nunca baixa total silenciosa. Pagamento a maior registra o excedente como receita de juros | decisão nova | 💡 |
| BR-510 | Cobrança cancelada, vencida ou falha não baixa o título — ele permanece em aberto e continua no aging | decisão nova | 💡 |
| BR-511 | Credenciais bancárias de cobrança são cifradas, de **escopo mínimo (somente cobrança, nunca pagamento ou transferência)** e jamais versionadas | segurança (pasta 25) | ✅ princípio de segurança |
| BR-512 | Venda a prazo com cobrança registrada referencia as duplicatas no grupo de cobrança da NF-e | legislação | 💡 **validar com contador** (G-02) |

## BR-6xx — Fiscal / NF-e

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-601 | Venda com envio interestadual ou para PJ exige NF-e; demais casos conforme orientação do contador | hipótese | 💡 **validar com contador** |
| BR-602 | Numeração de NF-e é sequencial por série, sem furos não justificados; números pulados são inutilizados na SEFAZ | legislação | ✅ |
| BR-603 | XML autorizado (e eventos) é guardado por no mínimo 5 anos com backup | legislação | ✅ |
| BR-604 | Cancelamento de NF-e apenas dentro do prazo legal (24h padrão SEFAZ) e sem circulação da mercadoria; fora disso, fluxo de devolução | legislação | ✅ |
| BR-605 | Emissão em homologação SEFAZ deve estar disponível permanentemente para testes | decisão nova | ✅ |
| BR-606 | Dados fiscais do produto (NCM, CFOP, CSOSN, origem) são obrigatórios antes da primeira emissão que o inclua | legislação | ✅ |
| BR-607 | Endereço de destinatário tem o **código IBGE do município** (`enderDest/cMun`, 7 dígitos) resolvido a partir de cidade + UF contra a tabela oficial; sem casamento **exato** o campo fica nulo e vira pendência do cadastro — nunca é aproximado por semelhança de nome | legislação + decisão nova ([ADR-0026](../27-ADR/ADR-0026-codigo-ibge-municipio.md)) | 🔧 **implementada em 2026-08-10**: `Sales\Services\ResolveIbgeCityCode` casa por `(uf, nome normalizado)` — chave `UNIQUE` na tabela, o que impede escolher entre homônimos (há Jacutinga em MG e no RS). Fallback é **escolher** o município na tela, não digitar número (FK barra código inexistente). Endereços antigos: `erp:enderecos:resolver-ibge` |
| BR-608 | Quando `tax_profiles` tiver CST/cClassTrib/alíquotas de IBS/CBS configurados para o cenário, a NF-e inclui o grupo `IBSCBS` (UB12) por item; sem configuração, o grupo é **omitido** — omissão não é falha para emitente do Simples Nacional antes de 04/01/2027 (LC 214/2025, art. 348, III, "c") | legislação + decisão nova ([ADR-0027](../27-ADR/ADR-0027-grupo-ibscbs-e-schema-pl010.md)) | 🔧 **implementada em 2026-08-11**: `TaxProfile::ibscbsConfigurado()` exige os 5 campos preenchidos (parcial = omitido, nunca meio-grupo); `tax_profiles` continua vazio nesses campos até o contador confirmar (H-05/H-06 da [pauta](../13-Fiscal/01-pauta-validacao-contador.md)) |

## BR-7xx — Integrações e migração

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-701 | Nenhum sistema externo acessa o banco do ERP; toda troca é via API autenticada | princípio do projeto | ✅ |
| BR-702 | Após o cutover, cadastrar/editar produto no wp-admin é proibido por política; a reconciliação sobrescreve com o valor do ERP e alerta | decisão nova | 💡 comunicar à equipe |
| BR-703 | Pedido criado no Woo é fato imutável de origem: o ERP importa, nunca recria ou duplica (idempotência por ID externo) | decisão nova | ✅ arquitetura (ADR-0007) |
| BR-704 | Toda entidade sincronizada mantém mapeamento ERP↔externo em `integration_mappings` | decisão nova | ✅ arquitetura (ADR-0007) |
| BR-705 | Falha de sincronização nunca bloqueia a operação local do ERP (fila com retry; degradação graciosa) | decisão nova | ✅ |
| BR-706 | Migração é idempotente e re-executável; produto/cliente já migrado é atualizado, não duplicado | decisão nova | ✅ (pasta 17) |
| BR-707 | Pedido do site grava endereço de entrega/cobrança (`order_addresses`, separado do endereço do cliente — entrega às vezes é para outra pessoa), nota do cliente e forma de entrega. Na carga histórica (`--historico`), pedido `completed` vira `Entregue` só como rótulo — sem lançar reserva nem baixa no ledger, porque o Estoque ainda não tem contagem física calibrada | decisão da diretoria (2026-08-11) | ✅ **implementado em 2026-08-11** |

## BR-8xx — Segurança e permissões

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-801 | Acesso é negado por padrão; toda ação passa por permissão explícita do papel | decisão nova | ✅ (ADR-0011) |
| BR-802 | Ações sensíveis (ajuste de estoque, cancelamento de NF-e, exclusões, mudança de preço em massa) são auditadas com autor, antes/depois e IP | decisão nova | ✅ (ADR-0012) |
| BR-803 | Contador tem perfil somente-leitura restrito a fiscal/relatórios | decisão nova | 💡 |
| BR-804 | 2FA obrigatório para papéis Admin e Financeiro | decisão nova | 💡 |
