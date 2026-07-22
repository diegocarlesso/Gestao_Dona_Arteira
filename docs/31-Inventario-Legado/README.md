# 31 — Inventário do Legado (Engenharia Reversa)

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** business-analyst / migration-specialist / woocommerce-specialist
> **Gate:** 01 — Descoberta do Domínio e Inventário do Legado
> **ADRs relacionados:** ADR-0006 (ERP SSOT), ADR-0007 (sync assíncrona), ADR-0010 (migração ETL), ADR-0008 (ledger de estoque), ADR-0016 (hospedagem), ADR-0017 (mídia)
> **Regras relacionadas:** BR-001..BR-008, BR-201..BR-207, BR-301..BR-309, BR-701..BR-706

## O que é esta pasta

Registro da **engenharia reversa** feita no Gate 01 sobre as fontes reais da operação da Dona Arteira. O objetivo **não** foi migrar nem copiar estruturas — foi **gerar conhecimento** sobre como a empresa trabalha, como os dados estão organizados, que regras implícitas existem e que problemas/oportunidades há.

Estes documentos são **descritivos** (retratam o que existe hoje). Decisões de arquitetura continuam na pasta [27-ADR](../27-ADR/README.md); regras de negócio continuam na pasta [01-Regras-de-Negocio](../01-Regras-de-Negocio/01-registro-de-regras.md). Onde este inventário sugere mudar algo já documentado, a sugestão está em [15-recomendacoes.md](15-recomendacoes.md) — **nada da documentação existente foi alterado**.

## Fontes analisadas

| # | Fonte | O que é | Uso |
|---|---|---|---|
| 1 | `docs/database_dump/u917402451_donaarteira.sql` (115 MB, 441.316 linhas) | Dump phpMyAdmin do site **WooCommerce** em produção (Hostinger) | Fonte primária — operação real |
| 2 | `Dona_Arteira_Gestao_desktop/` | Sistema desktop Python (SQLAlchemy) | Referência de regras (somente leitura) |
| 3 | `docs/` (Gate 00) | Documentação canônica do projeto | Base para cruzamento |

> ⚠️ **Importante:** o dump fornecido é o do **site WooCommerce**, não o do banco `dona_arteira` do sistema desktop. O banco do desktop **não** foi disponibilizado — ver pendência em [12-qualidade-dados.md](12-qualidade-dados.md) e [98-perguntas-para-o-negocio.md](98-perguntas-para-o-negocio.md).

## Método

O dump foi importado num banco **isolado e descartável** (`donaarteira_legado`, MariaDB local) e analisado por consultas SQL. Nenhum sistema externo foi acessado; nenhum dado de produção foi alterado. Todas as estatísticas deste inventário são reproduzíveis a partir do dump. Detalhes e limitações do método em [01-visao-geral.md](01-visao-geral.md#5-método-e-limitações).

## Índice

| Doc | Conteúdo |
|---|---|
| [01-visao-geral.md](01-visao-geral.md) | Retrato geral do ambiente, stack, método e números-âncora |
| [02-produtos.md](02-produtos.md) | Catálogo: contagens, completude, tipos, estoque, vendas |
| [03-categorias.md](03-categorias.md) | Árvore de categorias e contaminação por merchandising |
| [04-atributos.md](04-atributos.md) | Atributos globais (cor, altura, composição de kit) |
| [05-imagens.md](05-imagens.md) | Anexos/mídia, tipos, integridade, limitações |
| [06-clientes.md](06-clientes.md) | Base de clientes: PF/PJ, geografia, documentos, duplicidade |
| [07-pedidos.md](07-pedidos.md) | Pedidos: volume, período, status, ticket, sazonalidade |
| [08-formas-pagamento.md](08-formas-pagamento.md) | Gateways e métodos de pagamento |
| [09-formas-entrega.md](09-formas-entrega.md) | Fretes, zonas e retirada no local |
| [10-plugins.md](10-plugins.md) | Plugins ativos e inativos (tabelas órfãs) |
| [11-metadados.md](11-metadados.md) | Metadados (postmeta/usermeta/order meta) e candidatos à remoção |
| [12-qualidade-dados.md](12-qualidade-dados.md) | Relatório de qualidade: duplicidades, órfãos, anomalias, bloat |
| [13-oportunidades.md](13-oportunidades.md) | Oportunidades descobertas |
| [14-riscos.md](14-riscos.md) | Riscos de migração e de negócio |
| [15-recomendacoes.md](15-recomendacoes.md) | Recomendações + sugestões de alteração à doc existente |
| [16-mapa-entidades.md](16-mapa-entidades.md) | Mapa de entidades e relacionamentos do legado |
| [17-glossario-extraido.md](17-glossario-extraido.md) | Termos do negócio extraídos dos dados |
| [98-perguntas-para-o-negocio.md](98-perguntas-para-o-negocio.md) | Dúvidas para o dono/contador, agrupadas por tema |
| [99-relatorio-executivo.md](99-relatorio-executivo.md) | Síntese executiva |

## Números-âncora (validados)

| Métrica | Valor |
|---|---|
| Produtos (peças) publicados | **716** (677 simples + 39 variáveis) |
| Variações | 77 |
| Produtos **sem SKU** | **716 (100%)** |
| Categorias de produto | 48 |
| Anexos de mídia | 1.002 |
| Clientes registrados | 198 (+2 admin) |
| **Compradores reais** (CPF/e-mail distintos) | **62** |
| Pedidos (todos status) | 85 |
| Pedidos concluídos | 69–70 |
| Receita concluída (toda a vida) | **R$ 9.176,43** |
| Ticket médio (concluído) | R$ 131,09 |
| Período dos pedidos | 2021-11-26 → 2026-05-25 |

Estes números sustentam a conclusão central do Gate 01: **o WooCommerce é um canal de baixíssimo volume; o grosso da operação é offline** (balcão/atacado). Ver [99-relatorio-executivo.md](99-relatorio-executivo.md).
