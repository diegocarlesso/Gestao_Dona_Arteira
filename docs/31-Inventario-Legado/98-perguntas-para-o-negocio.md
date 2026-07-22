# 98 — Perguntas para o Negócio

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** business-analyst
> Dúvidas que **não puderam ser inferidas com segurança** a partir do dump ou da documentação. Cada uma explica **por que a resposta importa para a arquitetura do ERP**. Respostas viram BRs validadas (pasta 01) e parametrizações.

## Produção

**P-PROD-01 · Composição dos kits/trios (BOM).** Quais peças e **quantidades** compõem cada kit/trio? O site só tem a lista textual (`pa_peca-kit-1`). *Por quê:* 30% do catálogo é composto e lidera vendas; sem BOM não há como planejar produção, custear nem reservar componentes ([04](04-atributos.md)/[13](13-oportunidades.md)).

**P-PROD-02 · Etapas reais e exceções.** As etapas do glossário (Fundição→Secagem→Pintura→Acabamento→CQ) batem com a realidade? Alguma peça pula etapas (vendida crua)? *Por quê:* define a máquina de estados da OP ([BR-102](../01-Regras-de-Negocio/01-registro-de-regras.md)).

**P-PROD-03 · Revenda vs. fabricação própria.** Incensos (vareta/cone) e itens de **MDF** são comprados prontos? Que % do catálogo é revenda? *Por quê:* `kind` do produto, fluxo de compras e NCM fiscal diferem para revenda.

**P-PROD-04 · Acabamento/cores.** A paleta de 39 cores é padronizada? Cada combinação ("bronze velho", "marsala dourada") consome tintas específicas? *Por quê:* base da ficha técnica de pintura e do custo.

## Estoque

**P-EST-01 · Qual estoque é confiável?** O site quase não controla quantidade (708/716). O desktop (`in_stock`) reflete o físico? *Por quê:* decide a fonte do saldo inicial — a recomendação é **inventário físico no cutover** ([BR-204](../01-Regras-de-Negocio/01-registro-de-regras.md)/[R-03](14-riscos.md)).

**P-EST-02 · Locais de estoque.** Há mais de um local (ateliê, loja, feiras, consignação)? *Por quê:* modelagem de locais e disponibilidade.

**P-EST-03 · Buffer de canal.** Qual margem de segurança para publicar no site sem furar estoque? *Por quê:* parametriza [BR-204](../01-Regras-de-Negocio/01-registro-de-regras.md).

## Vendas

**P-VEN-01 · O desktop ainda é usado? Onde estão os clientes de atacado?** O banco `dona_arteira` não foi fornecido. Ele está em uso? *Por quê:* o site é 100% PF; o atacado/PJ vive fora dele — sem essa fonte a migração perde metade do negócio ([R-04](14-riscos.md)).

**P-VEN-02 · Critério de atacado.** Quem tem direito ao preço de atacado (PJ com IE? quantidade mínima?)? *Por quê:* [BR-301](../01-Regras-de-Negocio/01-registro-de-regras.md) — entrevista obrigatória.

**P-VEN-03 · Volume real por canal.** Quantos pedidos/mês no balcão/atacado vs. site? O site fez só 85 em 4,5 anos. *Por quê:* dimensiona o núcleo do ERP e a prioridade da integração Woo ([99](99-relatorio-executivo.md)).

**P-VEN-04 · Cancelados de alto valor.** Por que os maiores pedidos (até R$ 3.880) foram cancelados? Eram atacado/teste/falha de pagamento? *Por quê:* pode revelar demanda B2B reprimida ([07](07-pedidos.md)).

**P-VEN-05 · Encomendas.** O `delivery_date` do desktop indica encomenda sob medida. Como funciona hoje (prazo, sinal)? *Por quê:* [BR-307](../01-Regras-de-Negocio/01-registro-de-regras.md) e ligação com produção.

## Fiscal

**P-FIS-01 · Emite NF-e hoje?** Com qual ferramenta? O site tem `calc_taxes=no` e não emite. *Por quê:* define o ponto de partida do módulo NF-e ([14-NFe](../14-NFe/README.md)).

**P-FIS-02 · Regime e anexo do Simples.** Confirmar com o contador (hipótese: Simples, CSOSN 102). *Por quê:* base de toda a tributação ([BR-606](../01-Regras-de-Negocio/01-registro-de-regras.md)).

**P-FIS-03 · NCM por material.** Gesso decorado (hipótese 6809.90.00), MDF e incenso de revenda têm NCMs distintos. Quais? *Por quê:* obrigatório antes da 1ª emissão ([BR-606](../01-Regras-de-Negocio/01-registro-de-regras.md)).

**P-FIS-04 · Quando a venda exige nota?** Toda venda? Só envio/PJ? *Por quê:* [BR-601](../01-Regras-de-Negocio/01-registro-de-regras.md), regra de expedição x NF-e ([BR-309](../01-Regras-de-Negocio/01-registro-de-regras.md)).

## Financeiro

**P-FIN-01 · Taxas de gateway e parcelamento.** Como são contabilizadas as taxas de Mercado Pago/Pagaleve e os juros de parcelamento? *Por quê:* plano de contas e conciliação ([BR-503](../01-Regras-de-Negocio/01-registro-de-regras.md)).

**P-FIN-02 · Condições de pagamento no atacado.** Prazo (30/60)? *Por quê:* geração de títulos a receber ([BR-501](../01-Regras-de-Negocio/01-registro-de-regras.md)).

**P-FIN-03 · Plano de contas gerencial.** Existe um hoje (planilha)? *Por quê:* [BR-503](../01-Regras-de-Negocio/01-registro-de-regras.md).

## Integrações / Canais

**P-INT-01 · Marketplaces.** Shopee/Lazada (tabelas CedCommerce inativas) ainda operam por fora? Facebook/Google Shopping estão em uso? *Por quê:* escopo do multicanal ([10](10-plugins.md)/[13](13-oportunidades.md)).

**P-INT-02 · `sevensi-functions`.** Esse plugin de código customizado altera checkout/preço/estoque? Quem o mantém? *Por quê:* pode conter regras ocultas ([R-06](14-riscos.md)).

**P-INT-03 · Rastreio.** Como o cliente acompanha a entrega hoje (o plugin de order tracking está inativo)? *Por quê:* define o retorno ERP→Woo de rastreio.

## Dados / Migração

**P-DAT-01 · Dump do desktop.** É possível obter o dump do banco `dona_arteira`? *Por quê:* sem ele, atacado/balcão e dedupe cruzado ficam de fora ([R-04](14-riscos.md)).

**P-DAT-02 · Arquivos de imagem.** Qual o peso do `/wp-content/uploads`? Há imagens no FTP do desktop que não estão no site? Qual acervo é a verdade? *Por quê:* estratégia de mídia ([05](05-imagens.md)/[ADR-0017](../27-ADR/ADR-0017-midia-canonica.md)).

**P-DAT-03 · SKU/nomenclatura.** A empresa tem um padrão de código de peça (o desktop usa `code`)? Podemos adotá-lo/gerá-lo? *Por quê:* chave de casamento de todo o catálogo ([R-01](14-riscos.md)).

**P-DAT-04 · Duplicatas de produto.** Os 37 produtos de título repetido são o mesmo item ou variações? *Por quê:* evita duplicar catálogo na migração ([12](12-qualidade-dados.md)).

**P-DAT-05 · Contas sem compra.** As ~136 contas sem pedido devem ser migradas? (LGPD) *Por quê:* retenção de dados pessoais.

## Operação / Expedição

**P-OPE-01 · Retirada no local.** Como funciona o balcão/retirada (agenda, aviso "pronto")? É ~38% dos pedidos. *Por quê:* fluxo de fulfillment de 1ª classe ([09](09-formas-entrega.md)/[13](13-oportunidades.md)).

**P-OPE-02 · Quebra no transporte.** Frequência e política de reposição ao cliente? *Por quê:* perdas e pós-venda ([BR-104](../01-Regras-de-Negocio/01-registro-de-regras.md)).
