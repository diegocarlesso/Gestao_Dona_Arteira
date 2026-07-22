# 14 — Riscos

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** migration-specialist / chief-architect
> **Regras relacionadas:** BR-706 (migração idempotente), BR-204 (estoque publicado)

## 1. Objetivo

Consolidar os riscos descobertos na engenharia reversa — de dados, de migração e de negócio — com probabilidade, impacto e mitigação.

## 2. Matriz de riscos

| ID | Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|---|
| R-01 | **SKU ausente (100%)** impede casar produtos entre Woo, desktop e ERP | Alta | Alto | Gerar SKU no ETL e reconciliar com `pieces.code`; casar itens por `product_id` no ínterim ([02](02-produtos.md)) |
| R-02 | **Produtos duplicados** (37/14 grupos) geram duplicidade na migração | Alta | Médio | Dedupe por título+atributos antes da carga; revisão manual ([12](12-qualidade-dados.md)) |
| R-03 | **Estoque do site não é confiável** (708/716 sem quantidade) | Alta | Alto | **Inventário físico no cutover** ([BR-204](../01-Regras-de-Negocio/01-registro-de-regras.md), pasta 17); não importar saldo do site |
| R-04 | **Banco do desktop não fornecido** → clientes/pedidos de atacado invisíveis; dedupe cruzado impossível hoje | Alta | Alto | Obter o dump `dona_arteira`; tratar **duas fontes** na migração ([06](06-clientes.md), [98](98-perguntas-para-o-negocio.md)) |
| R-05 | **Descrições em HTML** (652/716); layout das páginas acoplado ao Avia/Enfold (meta de página, não a descrição) | Média | Médio | Higienização de HTML no ETL; descartar meta de page-builder ([02](02-produtos.md)/[11](11-metadados.md)) |
| R-06 | **`sevensi-functions`** (código customizado) pode conter regras/ajustes ocultos | Média | Médio | Auditar o plugin antes do Gate 02 ([10](10-plugins.md)) |
| R-07 | **Composição de kit não estruturada** (texto de vitrine) | Alta | Médio | Levantar BOM com a produção (entrevista); não assumir composição ([04](04-atributos.md)) |
| R-08 | **Categorias misturam tema, material, merchandising e sazonal** | Média | Médio | Reclassificar na migração; separar coleção/campanha de categoria ([03](03-categorias.md)) |
| R-09 | **Cancelados de alto valor** podem esconder problema (pagamento? atacado perdido?) | Média | Médio | Investigar com o negócio ([07](07-pedidos.md)) |
| R-10 | **HTML em títulos de pagamento**; nomes de frete inconsistentes | Alta | Baixo | Normalização/higienização no ETL ([08](08-formas-pagamento.md)/[09](09-formas-entrega.md)) |
| R-11 | **Cutover em pico sazonal** (maio/nov–dez) causaria ruptura | Média | Alto | Proibir cutover em pico ([07](07-pedidos.md), pasta 17) |
| R-12 | **Arquivos físicos de imagem não verificados** (só referências) | Média | Médio | Auditar `/uploads` e FTP antes de migrar mídia ([05](05-imagens.md)) |
| R-13 | **Edição no wp-admin pós-cutover** reintroduz divergência | Média | Médio | Política [BR-702](../01-Regras-de-Negocio/01-registro-de-regras.md) + reconciliação sobrescreve |
| R-14 | **Dependência de plugins BR específicos** (Extra Checkout Fields, Mercado Pago, Melhor Envio) na leitura dos dados | Baixa | Médio | Mapear metadados exatos (feito, [11](11-metadados.md)); ACL na integração |
| R-15 | **Fiscal do zero** — nunca se calculou imposto/emitiu NF-e no site | Alta | Alto | Validação obrigatória com contador antes do Gate 05 ([98](98-perguntas-para-o-negocio.md)) |

## 3. Riscos que a análise NÃO conseguiu avaliar (por falta de fonte)

- **Duplicidade cliente site × desktop** — depende do dump do desktop (R-04).
- **Existência física dos arquivos de imagem** — depende de acesso ao servidor/FTP (R-12).
- **Volume real do negócio offline** (balcão/atacado) — não está no dump; só o online foi medido.
- **Regras no `sevensi-functions`** — não auditado (R-06).

Essas lacunas viram itens de [98-perguntas-para-o-negocio.md](98-perguntas-para-o-negocio.md).

## 4. Risco arquitetural cruzado

O achado de que **o online é canal secundário de baixo volume** (85 pedidos em 4,5 anos) reforça que o **núcleo do ERP é o offline** (estoque, produção, balcão/atacado). Há risco de **superdimensionar a integração WooCommerce** frente ao seu peso real — a priorização deve refletir isso ([99](99-relatorio-executivo.md)). Relaciona-se ao [ADR-0016](../27-ADR/ADR-0016-hospedagem.md): o volume baixo **relaxa** requisitos de escala do canal, mas **não** os requisitos fiscais/de estoque do núcleo.
