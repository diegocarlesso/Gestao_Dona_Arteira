# 30 — Domínio da Dona Arteira

> **Status:** Rascunho — **este documento se completa com entrevistas** · **Última atualização:** 2026-07-03 · **Responsável:** business-analyst
> Aqui vive o conhecimento do NEGÓCIO (não do software). O que está confirmado vem das instruções do projeto e do sistema legado; o restante está explicitamente marcado como a descobrir.

## 1. O negócio (confirmado)

A Dona Arteira fabrica artesanalmente e comercializa **peças decorativas em gesso pintadas manualmente**. Processo produtivo: compra de matéria-prima → fundição em moldes → secagem → pintura manual → acabamento → controle de qualidade → estoque → venda → separação → embalagem → expedição → NF-e → financeiro.

Canais de venda hoje:
- **E-commerce** próprio em WordPress/WooCommerce (produtos, clientes, pedidos, imagens, estoque e histórico — patrimônio de dados a migrar).
- **Vendas diretas** registradas no sistema desktop (balcão/atacado — o legado tem preço de atacado e CNPJ, indicando venda a lojistas).

Evidências do legado relevantes para o negócio:
- Duas listas de preço (varejo/atacado) por peça.
- Embalagem padrão por peça com dimensões/peso → frete calculado por embalagem.
- Pedidos com data de entrega futura (encomendas) e opção retirada/entrega.
- Pagamentos: dinheiro, PIX, cartão, boleto.
- Imagens hospedadas via FTP na própria hospedagem.

## 2. Características do produto que moldam o sistema

| Característica | Consequência no ERP |
|---|---|
| Gesso é frágil | perdas por quebra em produção, manuseio e transporte são evento normal → registro de perdas em todo o fluxo (BR-104) + embalagem cuidadosa (checklist) |
| Pintura manual | peças "iguais" variam; tempo de pintura é gargalo; produtividade por pessoa interessa |
| Secagem depende do clima | lead time variável → datas prometidas com folga; `drying_days` por peça é estimativa, não contrato |
| Moldes se desgastam | vida útil controlada; molde novo é investimento (categoria financeira própria) |
| Produção em lotes pequenos | OPs de poucas dezenas; UX de apontamento deve ser leve |
| Sazonalidade provável (datas comemorativas) | picos de venda → buffer de estoque no site, proibição de cutover em nov/dez, planejamento de produção antecipado |

## 3. Roteiro de descoberta (entrevistas obrigatórias antes dos Gates 02–03)

### Produção (com quem produz)
1. Quais as etapas reais e sua ordem? Alguma peça pula etapas (ex.: vendida crua)?
2. Quantos dias de secagem por tipo/tamanho de peça? Varia com estação?
3. Quem faz o quê (fundição/pintura/acabamento)? Quantas pessoas?
4. % típico de quebra por etapa? Onde dói mais?
5. Moldes: quantos existem? São identificados/numerados? Quantos usos aguentam? Quem os fabrica (própria/terceiro)?
6. Receita (ficha técnica): existe anotada? kg de gesso por peça? tintas?
7. Produz-se para estoque, sob encomenda, ou misto? Como decidem o que produzir na semana?
8. O que anotam hoje (caderno/planilha)? — migrar o hábito, não impor um novo.

### Vendas
9. Critério real do preço de atacado (quem tem direito, quantidade mínima)? (BR-301)
10. Volume mensal por canal? Ticket médio? Época de pico?
11. Personalizações sob medida existem? Como cotam?
12. Feiras/consignação existem? (afetaria locais de estoque)

### Expedição
13. Transportadoras usadas hoje? Correios? Melhor Envio já em uso no site?
14. Quebra no transporte: frequência, política de reposição ao cliente?

### Financeiro/fiscal
15. Regime tributário e anexo do Simples (contador). Emitem NF-e hoje? Com qual ferramenta?
16. Condições de pagamento no atacado (prazo? 30/60?)?

### Dados
17. Quantos produtos/clientes/pedidos ativos estimam? O estoque do site condiz com o físico?

Registro: respostas viram BRs validadas (pasta 01) e parametrizações; este documento é atualizado com a seção "Confirmado".

## 4. Riscos

| Risco | Mitigação |
|---|---|
| Construir o Gate 03 sobre suposições de produção | Entrevistas são pré-requisito formal do gate (pasta 28) |
| Vocabulário do sistema não bater com o do ateliê | Glossário validado nas entrevistas; UI usa os termos deles |

## 5. Evoluções futuras

- Registrar aqui: catálogo de linhas de produto, calendário sazonal oficial, política de reposição por quebra — conforme forem descobertos.
