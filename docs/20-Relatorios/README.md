# 20 — Relatórios

> **Status:** Em revisão · **Última atualização:** 2026-07-03 · **Responsável:** business-analyst
> **Fase:** Gate 06 (essenciais antecipados por módulo) · Dashboards: [pasta 21](../21-Dashboards/README.md)

## 1. Objetivo

Catálogo canônico dos relatórios do ERP e padrões de construção: todo relatório é uma **consulta documentada, testada e exportável** — nunca uma query solta que ninguém sabe explicar.

## 2. Padrões de construção (skill `criar-relatorio`)

1. Cada relatório tem ficha neste catálogo: pergunta de negócio que responde, filtros, colunas, fonte (módulos), permissão, dono.
2. Consulta implementada como classe de leitura dedicada (query object) — sem impacto em telas transacionais; consultas pesadas rodam com limites e são candidatas a snapshot noturno (fase 6).
3. Teste com dataset fixo (factory) que valida totais — relatório errado é pior que sem relatório.
4. Exportação CSV sempre; PDF quando for documento de trabalho (ex.: lista de separação).
5. Datas com visão competência × caixa explícita nos financeiros (pasta 12).

## 3. Catálogo inicial

| Relatório | Pergunta que responde | Módulos | Fase |
|---|---|---|---|
| Vendas por período/canal/categoria | quanto vendemos, onde? | Vendas | 2 |
| Curva ABC de produtos | quais peças sustentam o faturamento? | Vendas | 6 |
| Margem por produto | qual peça dá lucro de verdade? (preço − custo médio) | Vendas+Estoque | 6 |
| Encomendas em aberto por data prometida | o que precisa ser produzido primeiro? | Vendas+Produção | 3 |
| Posição de estoque (por tipo/local) | o que temos agora? | Estoque | 1 |
| Extrato de movimentos por produto | por que o saldo está assim? | Estoque | 1 |
| Estoque abaixo do mínimo | o que repor/produzir? | Estoque | 2 |
| Giro de estoque | o que está parado? | Estoque+Vendas | 6 |
| Perdas por etapa/motivo | onde estamos quebrando peças? | Produção | 3 |
| Produtividade por etapa/pessoa | gargalos do ateliê? | Produção | 6 |
| Consumo de MP por período | quanto gesso/tinta usamos? | Produção+Estoque | 3 |
| Vida útil de moldes | quais moldes vão vencer? | Produção | 3 |
| Contas a receber/pagar com aging | quem nos deve / a quem devemos? | Financeiro | 4 |
| Fluxo de caixa realizado × projetado | vamos fechar o mês? | Financeiro | 4 |
| DRE gerencial simplificada | resultado do mês por categoria | Financeiro | 6 |
| NF-e emitidas por período (+ XMLs em lote) | obrigação com contador | Fiscal | 5 |
| Divergências de sincronização | ERP e Woo estão iguais? | Integrações | 2 |
| Pedidos por status (funil operacional) | o que está travado? | Vendas | 2 |
| **Cadastro incompleto** | que produtos ainda não podem ser vendidos direito? | Catálogo | 1 |
| **Produtos com nome repetido** | temos a mesma peça cadastrada duas vezes? | Catálogo | 2 |

### 3.1 Cadastro incompleto — por que é relatório, e não só aviso de tela

Pedido do dono em 2026-07-27, durante o saneamento da migração.

A tela de produtos já sinaliza a lacuna item a item (pasta 32 §3.6): sem
peso, sem preço de varejo, sem preço de atacado. Isso serve para quem
está com **aquele** produto aberto — e não serve para quem precisa saber
**quanto falta** e **por onde começar**.

São perguntas diferentes, e a segunda é de relatório: lista filtrável,
com contagem e exportação em CSV, para a equipe dividir o trabalho de
preencher. A migração entrega os números iniciais — 35 produtos sem peso
e 21 sem preço entre os 754 —, mas o relatório continua útil depois dela:
todo produto novo nasce podendo ter lacuna.

Fica na fase 1 junto com o catálogo, e não na 6 com os demais, porque é
disso que a equipe precisa **enquanto** completa a carga inicial.

**Produtos com nome repetido** responde à outra pergunta do saneamento —
os 37 anúncios que o WooCommerce tinha em duplicidade
([17/F3](../17-Migracao/README.md)). Fica na fase 2 porque, terminada a
migração, vira zeladoria: pega o cadastro feito em duplicidade por
distração, que é o mesmo problema chegando por outra porta.

## 4. Dependências

Consome todos os módulos; depende de 19 (permissão `reports.view` + recortes por papel) e 22 (testes de totais).

## 5. Riscos

| Risco | Mitigação |
|---|---|
| Relatório pesado degradar a operação | Query objects com índices planejados (pasta 04); limites; snapshot noturno para os pesados |
| Números divergirem entre relatórios ("qual é o certo?") | Definições únicas documentadas na ficha (ex.: "venda = pedido expedido", não "pago") — glossário de métricas na pasta 21 |

## 6. Evoluções futuras

- Agendamento com envio por e-mail (fase 6) · exportação contábil para o contador (fase 5–6) · BI externo somente-leitura via réplica (se VPS, fase 7).
