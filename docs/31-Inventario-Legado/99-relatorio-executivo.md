# 99 — Relatório Executivo do Gate 01

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** chief-architect / business-analyst
> Síntese da engenharia reversa. Detalhes e números em [01](01-visao-geral.md)–[17](17-glossario-extraido.md). Perguntas em [98](98-perguntas-para-o-negocio.md).

## 1. Como a empresa realmente opera?

A Dona Arteira é um **ateliê de peças decorativas em gesso pintadas à mão** em **Jacutinga/RS**, com um **catálogo espiritual/devocional** (Arte Sacra, Budas, Ganeshas, Orixás, Incensários, Elefantes, Africanas) e forte presença de **produtos compostos** — **kits e trios (30% do catálogo e líderes de venda)**. Vende também **revenda** (incensos) e uma pequena linha de **MDF**.

A descoberta mais importante do Gate 01 é de **proporção de canais**:

> **O WooCommerce é um canal secundário de baixíssimo volume: 85 pedidos em ~4,5 anos, R$ 9.176 de receita concluída, ticket médio R$ 131, 62 compradores.** O grosso da operação — balcão, atacado a lojistas, encomendas — acontece **fora do site**, provavelmente no sistema desktop (que tem preço de atacado e CNPJ, ausentes no site).

Sinais que sustentam isso: **~38% dos pedidos são retirada no local**; **61% dos clientes são do RS**; o site é **100% Pessoa Física** (nenhum PJ), enquanto o desktop modela atacado; e **88% dos produtos nunca venderam online**. A empresa também **experimentou vários canais digitais** (Shopee, Lazada, Facebook, Google Shopping, múltiplos funis) sem consolidar.

**Implicação estratégica:** o coração do ERP é o **offline** (estoque, produção, vendas de balcão/atacado, financeiro, fiscal). A integração WooCommerce é importante, mas **não deve ser superdimensionada** frente ao seu peso real.

## 2. O banco atual tem boa qualidade?

**Qualidade mista, mas gerenciável.**

✅ **Bom:** integridade referencial sólida (0 órfãos em postmeta/termos/variações); **todos** os produtos têm preço, categoria, descrição longa e imagem; 0 imagens órfãs/duplicadas; base de clientes **sem duplicatas** de CPF/e-mail; CPF capturado em 100% dos pedidos.

🔴 **Ruim:** **SKU 100% ausente** (produtos e variações); **estoque praticamente não controlado** (708/716 sem quantidade); **37 produtos com título duplicado**; categorias que **misturam tema, material, merchandising e sazonalidade**; descrições **em HTML** e páginas acopladas ao page-builder Avia/Enfold.

🗑️ **Ruído:** **~90% do dump é log/cache/plugin morto** (205 mil eventos de e-mail, 20 mil da fila, revisões, tabelas de plugins inativos). O acervo de negócio real é **pequeno**.

## 3. Quais dados poderão ser migrados diretamente?

Com saneamento leve:
- **Catálogo base:** nome, preço de varejo, categorias (árvore), atributos (cor/altura), imagem destaque, peso/dimensões (670/716 completos).
- **Clientes:** nome, e-mail, CPF, telefone, endereço BR (do plugin brasileiro) — base limpa para dedupe.
- **Pedidos (histórico):** cabeçalho, itens (por `product_id`), valores, pagamento, frete, status (mapeável).
- **Estrutura de categorias/tags/atributos.**

## 4. Quais dados precisarão de tratamento?

| Dado | Tratamento |
|---|---|
| **SKU** | **Gerar** e reconciliar com `pieces.code` do desktop (🔴 chave de tudo) |
| **Estoque** | **Não migrar** do site — inventário físico no cutover |
| **Produtos duplicados** | Deduplicar por título/atributos (37/14 grupos) |
| **Descrições** | Higienizar HTML (sem shortcodes); descartar meta de page-builder |
| **Categorias** | Reclassificar merchandising/sazonal/material |
| **Kits/trios** | Estruturar BOM (levantar com produção) |
| **Títulos de pagamento** | Remover HTML |
| **Métodos de frete** | Normalizar nomes inconsistentes |
| **Clientes site × desktop** | Dedupe cruzado (depende do dump do desktop) |

## 5. Quais riscos existem?

Os principais ([14](14-riscos.md)): **SKU ausente** (R-01), **estoque não confiável** (R-03), **dump do desktop indisponível** (R-04, esconde o atacado), **kits sem BOM** (R-07), **código customizado `sevensi-functions` não auditado** (R-06), **cutover em pico sazonal** (R-11) e **fiscal do zero** (R-15). Nenhum é impeditivo; todos têm mitigação conhecida.

## 6. Quais decisões arquiteturais deverão ser revistas?

Nenhum ADR precisa ser **revogado**; alguns ganham **evidência/ajuste** (sugestões em [15](15-recomendacoes.md), não aplicadas):

- **ADR-0010 (ETL):** confirmar tratamento de **duas fontes** (Woo + desktop) e de **prefixo SERVMASK_PREFIX_**; SKU vira etapa obrigatória de geração, não só saneamento.
- **ADR-0006/0007 (SSOT/sync):** válidos; o baixo volume do canal **relaxa** requisitos de escala da sync (não os de correção).
- **ADR-0016 (hospedagem):** o volume real (dezenas de pedidos/ano no site) **reduz a pressão de performance do canal**, mas **não** os requisitos de NF-e/estoque do núcleo — a recomendação de VPS segue válida por causa do fiscal e das filas, não do tráfego do site. **Decisão do dono ainda pendente** (prazo: fim do Gate 01).
- **ADR-0008 (ledger):** reforçado — nenhuma fonte tem estoque confiável; o ledger nasce sem herança.
- **ADR-0017 (mídia):** manter mídia no Woo na fase 1 evita mover o acervo de ~1.000 imagens no cutover; auditar peso do `/uploads` antes.

## 7. Que oportunidades foram encontradas?

Destaques ([13](13-oportunidades.md)): **unificar o multicanal** hoje fragmentado; **trazer o atacado para o digital** (há demanda B2B batendo no site sem lugar — vide cancelados de R$ 3.880); **controle real de estoque** (inexistente hoje); **estruturar kits com BOM**; **catálogo limpo e codificado** desde o início; **retirada local como fluxo de 1ª classe**; e **produção planejada por sazonalidade** (maio/Dia das Mães).

## 8. Que informações ainda faltam (com o contador e o negócio)?

**Com o contador** ([98](98-perguntas-para-o-negocio.md) · Fiscal/Financeiro): emite NF-e hoje e como? Regime/anexo do Simples e CSOSN? **NCM por material** (gesso vs MDF vs incenso de revenda)? Quando a venda exige nota? Como contabilizar taxas de gateway e juros de parcelamento?

**Com o negócio** (Produção/Vendas/Dados): composição real dos kits (BOM)? O desktop ainda é usado e onde estão os clientes de atacado? Qual estoque é confiável? Por que os grandes pedidos foram cancelados? Podemos obter o **dump do desktop** e auditar as **imagens físicas**?

## 9. Conclusão

O Gate 01 confirma o mapa do negócio da pasta 30 **com dados reais** e o enriquece com um recorte decisivo: **o digital é pequeno; o núcleo é o ateliê e o offline**. Os dados existentes são **suficientes para iniciar a migração de catálogo, clientes e pedidos** após saneamento — sobretudo **SKU** e **estoque**. As lacunas (desktop, BOM, fiscal) são **conhecidas, nomeadas e endereçadas** em perguntas objetivas para as entrevistas obrigatórias dos Gates 02–03. **Nenhum código de ERP foi criado; nenhuma documentação existente foi alterada.**
