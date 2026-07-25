# Registro de Regras de Negócio

> **Status:** Em revisão · **Última atualização:** 2026-07-03 · **Responsável:** business-analyst
> Legenda de status: 💡 Hipótese · ✅ Validada · 🔧 Implementada · ❌ Revogada

Regras nascem 💡 e só viram ✅ com validação nominal (quem validou + data). Regras extraídas do sistema legado indicam origem `legado`.

## BR-0xx — Gerais e cadastros

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-001 | CPF/CNPJ de cliente é obrigatório para faturamento (NF-e), validado por dígito verificador, e único no cadastro | legado (`validators.py`, unique em `clients.cpf_cnpj`) | 💡 validar se cliente de balcão sem NF pode ser cadastrado sem documento |
| BR-002 | Todo produto (peça) tem código (SKU) único e imutável após criação | **esquema** do desktop (`pieces.code` unique) — nunca operado | ✅ **decidido** ([ADR-0022](../27-ADR/ADR-0022-modelo-de-produto-e-sku.md)): formato `DA-0001`, sequencial e **sem significado embutido**, gerado pelo ERP. Nem o Woo (0/716) nem o desktop (nunca alimentado) têm SKU: **o ERP é a primeira origem de código da empresa** — ninguém tem código decorado a preservar |
| BR-003 | Toda peça possui dois preços de lista: varejo e atacado | **esquema** do desktop (`price_retail`, `price_wholesale`) — sistema que nunca entrou em operação | ⚠️ **A CONFIRMAR COM O NEGÓCIO.** Esta regra foi derivada do *esquema* do desktop, não de prática observada: o sistema nunca foi alimentado, e o WooCommerce só tem varejo. Ou seja, **ninguém confirmou que a empresa pratica preço de atacado**. O campo existe e aceita nulo; venda de atacado com preço manual até haver resposta. Ver [pasta 32 §3.5](../32-Catalogo/README.md) e BR-301 (critério de elegibilidade) |
| BR-004 | Toda peça vendável tem embalagem padrão associada, com dimensões e peso — insumo do cálculo de frete | legado (`packages` + `pieces.package_id`) | 💡 |
| BR-005 | Peça tem dimensões (A×L×P cm) e peso (g) próprios, distintos dos da embalagem | legado | 💡 |
| BR-006 | Cliente pode ser PF (CPF) ou PJ (CNPJ); PJ com IE habilita operações de atacado/revenda | derivada | 💡 |
| BR-007 | Categorias de produto formam árvore (categoria → subcategoria), espelhada do WooCommerce na migração | Woo | 💡 |
| BR-008 | Cadastros nunca são excluídos fisicamente se possuem movimento; são inativados (soft delete/arquivamento) | decisão nova | ✅ arquitetura (ADR-0002) |
| BR-009 | Cada acabamento (cor) e tamanho de uma peça é um **produto próprio, com SKU e saldo próprios** — não é atributo de um produto-pai | decisão nova | ✅ **decidido** ([ADR-0022](../27-ADR/ADR-0022-modelo-de-produto-e-sku.md)): a cor é acabamento de pintura manual, produzido e estocado separadamente. Regra nova porque o legado não a expressava: o WooCommerce usava variações e o desktop não modelava variação alguma |

## BR-1xx — Produção

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-101 | Toda produção ocorre via Ordem de Produção (OP); não existe entrada de produto acabado sem OP (exceto ajuste de inventário auditado) | decisão nova | 💡 |
| BR-102 | A OP percorre etapas fixas: Fundição → Secagem → Pintura → Acabamento → Controle de Qualidade; etapas podem ser puladas apenas por configuração da peça (ex.: peça vendida crua, sem pintura) | entrevista pendente | 💡 |
| BR-103 | Consumo de matéria-prima (gesso, tinta, verniz) é apontado na OP e baixa estoque de MP via movimento | decisão nova | 💡 |
| BR-104 | Perdas (quebras) são registradas por etapa com motivo; peça reprovada no CQ vira perda ou retrabalho | entrevista pendente | 💡 |
| BR-105 | Fundição consome usos do molde; molde tem vida útil estimada e alerta de reposição | entrevista pendente | 💡 confirmar se controlam moldes hoje |
| BR-106 | Secagem tem lead time mínimo por peça (dias); peças em secagem são WIP indisponível para venda | entrevista pendente | 💡 |
| BR-107 | Só peças aprovadas no CQ entram no estoque de produto acabado | decisão nova | 💡 |
| BR-108 | Custo de produção = MP consumida (custo médio) + custos configuráveis (mão de obra/overhead por rateio simples); fase 3 usa custo padrão revisado periodicamente | decisão nova | 💡 |

## BR-2xx — Estoque

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-201 | Saldo de estoque nunca fica negativo; movimento que resultaria em negativo é rejeitado (exceção: ajuste de inventário com permissão específica) | decisão nova | ✅ arquitetura (ADR-0008) |
| BR-202 | Todo movimento de estoque é imutável e referencia sua origem (OP, pedido, compra, ajuste); estorno é um contra-movimento | decisão nova | ✅ arquitetura (ADR-0008) |
| BR-203 | Pedido confirmado **reserva** estoque; expedição baixa; cancelamento libera a reserva | decisão nova | 💡 |
| BR-204 | Estoque publicado no WooCommerce = disponível (físico − reservado) − buffer de segurança configurável por produto | decisão nova | 💡 definir buffer padrão |
| BR-205 | Ajuste de inventário exige contagem registrada, motivo e aprovação de quem NÃO fez a contagem (segregação) | decisão nova | 💡 |
| BR-206 | Custeio por média móvel: cada entrada por compra/produção recalcula o custo médio do item | decisão nova | 💡 validar com contador |
| BR-207 | Estoque é segmentado por tipo: matéria-prima, em processo (WIP), produto acabado, embalagem, revenda | decisão nova | 💡 |

## BR-3xx — Vendas

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-301 | Preço atacado aplica-se a clientes marcados como atacadistas e/ou pedidos acima de quantidade mínima — critério a definir | legado (dois preços) | 💡 **entrevista obrigatória** |
| BR-302 | Preço do item é congelado no pedido (snapshot); mudança de tabela não altera pedidos existentes | legado (`order_items.price`) | ✅ padrão de mercado |
| BR-303 | Pedido segue máquina de estados única independente do canal: Rascunho → Confirmado → Pago → Em separação → Embalado → Expedido → Entregue (+ Cancelado/Devolvido) | decisão nova | 💡 |
| BR-304 | Pedidos do WooCommerce entram no ERP com status mapeado do status Woo e **não são editáveis** no ERP exceto avanço de fulfillment | decisão nova | 💡 |
| BR-305 | Desconto manual acima de limite configurável exige aprovação de alçada superior | decisão nova | 💡 definir limite |
| BR-306 | Entrega pode ser Retirada ou Envio; envio exige endereço validado e cálculo de frete | legado (`DeliveryMethod`) | 💡 |
| BR-307 | Encomenda (produto sem estoque) é permitida e gera demanda de produção com data prometida | legado (`delivery_date`) | 💡 confirmar fluxo real |
| BR-308 | Formas de pagamento aceitas: Dinheiro, PIX, Cartão, Boleto, Outro — parametrizável | legado (`PaymentMethod`) | 💡 |
| BR-309 | Expedição só ocorre com NF-e autorizada quando a operação exigir documento fiscal (ver BR-601) | decisão nova | 💡 validar exceções com contador |

## BR-4xx — Compras

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-401 | Entrada de MP em estoque só via recebimento de pedido de compra (com conferência de quantidade) ou ajuste auditado | decisão nova | 💡 |
| BR-402 | Recebimento atualiza custo médio do item e gera conta a pagar | decisão nova | 💡 |
| BR-403 | Divergência entre pedido e recebimento (falta/sobra/avaria) é registrada e não bloqueia entrada parcial | decisão nova | 💡 |

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

## BR-7xx — Integrações e migração

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-701 | Nenhum sistema externo acessa o banco do ERP; toda troca é via API autenticada | princípio do projeto | ✅ |
| BR-702 | Após o cutover, cadastrar/editar produto no wp-admin é proibido por política; a reconciliação sobrescreve com o valor do ERP e alerta | decisão nova | 💡 comunicar à equipe |
| BR-703 | Pedido criado no Woo é fato imutável de origem: o ERP importa, nunca recria ou duplica (idempotência por ID externo) | decisão nova | ✅ arquitetura (ADR-0007) |
| BR-704 | Toda entidade sincronizada mantém mapeamento ERP↔externo em `integration_mappings` | decisão nova | ✅ arquitetura (ADR-0007) |
| BR-705 | Falha de sincronização nunca bloqueia a operação local do ERP (fila com retry; degradação graciosa) | decisão nova | ✅ |
| BR-706 | Migração é idempotente e re-executável; produto/cliente já migrado é atualizado, não duplicado | decisão nova | ✅ (pasta 17) |

## BR-8xx — Segurança e permissões

| ID | Regra | Origem | Status |
|---|---|---|---|
| BR-801 | Acesso é negado por padrão; toda ação passa por permissão explícita do papel | decisão nova | ✅ (ADR-0011) |
| BR-802 | Ações sensíveis (ajuste de estoque, cancelamento de NF-e, exclusões, mudança de preço em massa) são auditadas com autor, antes/depois e IP | decisão nova | ✅ (ADR-0012) |
| BR-803 | Contador tem perfil somente-leitura restrito a fiscal/relatórios | decisão nova | 💡 |
| BR-804 | 2FA obrigatório para papéis Admin e Financeiro | decisão nova | 💡 |
