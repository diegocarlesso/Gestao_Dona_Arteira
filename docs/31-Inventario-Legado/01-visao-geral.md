# 01 — Visão Geral do Legado

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** woocommerce-specialist
> **ADRs relacionados:** ADR-0016 (hospedagem), ADR-0006 (SSOT) · **Regras relacionadas:** BR-701..BR-706

## 1. Objetivo

Dar o retrato geral do ambiente digital atual da Dona Arteira: onde está, com que tecnologia roda, como o dump está estruturado e quais os números-âncora que contextualizam todos os demais documentos deste inventário.

## 2. A loja (identidade e configuração)

| Item | Valor (extraído de `options`) |
|---|---|
| Nome | **DONA ARTEIRA** |
| URL | https://donaarteira.com.br |
| E-mail admin | ti@donaarteira.com.br |
| Endereço da loja | Rua João Tortelli, nº 530 — Centro, **Jacutinga/RS**, CEP 99730-000 |
| País/UF padrão | `BR:RS` |
| Moeda | BRL |
| Unidades | peso `kg`, dimensão `cm` |
| Cálculo de impostos | **desligado** (`woocommerce_calc_taxes = no`) |
| Gestão de estoque global | ligada (`manage_stock = yes`), mas **desligada por produto** (ver [02](02-produtos.md)) |
| Alerta de estoque baixo | 2 unidades |
| HPOS (tabela de pedidos nova) | **desligado** (`custom_orders_table_enabled = no`) |

**Leitura de negócio:** a loja é fisicamente sediada em **Jacutinga, no norte do RS** — o que explica a forte concentração de clientes e a alta taxa de **retirada no local** (ver [06](06-clientes.md) e [09](09-formas-entrega.md)). A empresa **não calcula impostos no checkout**: a questão fiscal é tratada fora do site (provável emissão manual/contador) — insumo direto para os módulos [13-Fiscal](../13-Fiscal/README.md)/[14-NFe](../14-NFe/README.md).

## 3. Stack técnico

| Camada | Tecnologia |
|---|---|
| CMS / e-commerce | WordPress + **WooCommerce 10.7.0** (versão recente) |
| Banco (origem) | **MariaDB 11.8.8** (Hostinger); `db_version` 60717 (WP ~6.7/6.8) |
| Tema | **Enfold** (`enfold-child`) com o construtor **Avia Layout Builder** |
| Hospedagem | **Hostinger** (conta `u917402451`, plugins `hostinger`, `hostinger-reach`, `hostinger-ai-assistant`) |
| PHP (na geração do dump) | 7.2.34 (informado pelo phpMyAdmin — provavelmente do servidor de export) |

A presença nativa de plugins Hostinger corrobora o cenário do [ADR-0016](../27-ADR/ADR-0016-hospedagem.md): o WordPress permanece na Hostinger; a decisão pendente é apenas **onde roda o ERP**.

## 4. Estrutura do dump

- **Formato:** dump phpMyAdmin 5.2.2, gerado em **2026-07-03 19:53**, com `START TRANSACTION` (sem `DROP DATABASE`).
- **Prefixo das tabelas:** `SERVMASK_PREFIX_` — assinatura do plugin **All-in-One WP Migration (ServMask)**. O prefixo real do site foi substituído por esse marcador na exportação.
- **Instalação `wp_` vestigial:** ao final do dump há um conjunto de tabelas com prefixo `wp_` **quase vazio** (`wp_posts` = 6, `wp_users` = 1, `wp_options` = 173). É uma instalação WordPress padrão/residual convivendo no mesmo banco — **não** contém dados da loja. **Toda a operação real está em `SERVMASK_PREFIX_*`.** Ver [12](12-qualidade-dados.md).
- **Volume:** 115 MB, 441.316 linhas, **~167 tabelas** — mas ~80% do peso é **log** (ver §6).

## 5. Método e limitações

**Método.** O dump foi importado num banco isolado e descartável (`donaarteira_legado`) num MariaDB **10.4.32** local e consultado por SQL. Estatísticas reproduzíveis; nenhum dado de produção tocado; nenhum sistema externo acessado (aderente ao [BR-701](../01-Regras-de-Negocio/01-registro-de-regras.md)).

**Limitações conhecidas:**
1. **8 tabelas do Wordfence não importaram** (`wf*`: `wfblockediplog`, `wfblocks7`, `wfcrawlers`, `wffilemods`, `wflivetraffichuman`, `wflocs`, `wfreversecache`, `wftrafficrates`) por sintaxe exclusiva do MariaDB 11 (`DEFAULT x AS ...`) não suportada na 10.4. São tabelas de **segurança/log**, irrelevantes ao domínio — perda nula para a análise.
2. **Arquivos físicos de imagem não estão no dump** (um dump SQL não carrega o filesystem). A existência real dos arquivos em `/wp-content/uploads` **não pôde ser verificada** — apenas as referências no banco. Ver [05](05-imagens.md).
3. **O banco do desktop (`dona_arteira`) não foi fornecido** — só o do WooCommerce. Clientes/pedidos que existam **apenas** no desktop (balcão/atacado) não estão neste inventário. Ver [98](98-perguntas-para-o-negocio.md).
4. Mojibake de acentuação observado no terminal de análise é **apenas de exibição**; os dados no banco estão íntegros em UTF-8 (nomes reproduzidos corretamente nos documentos).

## 6. Retrato de volume (o que pesa no dump)

| Tabela | Linhas | Natureza |
|---|---:|---|
| `wpmailsmtp_debug_events` | **205.746** | Log de e-mails (WP Mail SMTP) — descartável |
| `actionscheduler_actions` | 20.186 | Fila de tarefas do WooCommerce — descartável |
| `postmeta` | 76.324 | Metadados (muito de page-builder) |
| `posts` (revisões) | 821 | Revisões de conteúdo — descartável |
| `woocommerce_sessions` | 232 | Carrinhos/sessões transitórias |
| `cartflows_ca_cart_abandonment` | 80 | Carrinhos abandonados |

**Conclusão:** o "peso" do dump é enganoso — **~90% é log e cache**. O acervo de negócio (produtos, clientes, pedidos, mídia) é **pequeno**. Ver dimensionamento completo em [12](12-qualidade-dados.md).

## 7. Números-âncora (base de todo o inventário)

| Domínio | Números |
|---|---|
| **Catálogo** | 716 produtos (677 simples + 39 variáveis) · 77 variações · 48 categorias · 9 tags · 4 atributos globais |
| **Mídia** | 1.002 anexos (891 JPG · 108 PNG · 1 HEIC · 2 ZIP) |
| **Clientes** | 200 usuários (198 clientes + 2 admin) · **62 compradores reais** |
| **Pedidos** | 85 pedidos · 69–70 concluídos · período 2021-11 → 2026-05 |
| **Financeiro (online)** | Receita concluída **R$ 9.176,43** em ~4,5 anos · ticket médio **R$ 131,09** |

> A leitura estratégica desses números está em [99-relatorio-executivo.md](99-relatorio-executivo.md): **o site é um canal secundário de baixo volume**; a operação principal da empresa acontece fora dele.

## 8. Dependências

| Depende de | Motivo |
|---|---|
| Dump WooCommerce | fonte primária de todos os números |
| Desktop Python | referência de regras cruzada em [16](16-mapa-entidades.md)/[17](17-glossario-extraido.md) |
| Pasta 16-WooCommerce | este inventário resolve as "Pendências de inventário (Gate 02)" daquele doc |

## 9. Perguntas em aberto

Consolidadas em [98-perguntas-para-o-negocio.md](98-perguntas-para-o-negocio.md). As mais estruturantes: (a) o desktop ainda é usado e onde estão os clientes de atacado? (b) qual fonte de estoque é confiável, já que o site praticamente não controla quantidade? (c) a empresa emite NF-e hoje?
