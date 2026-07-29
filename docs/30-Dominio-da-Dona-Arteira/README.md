# 30 — Domínio da Dona Arteira

> **Status:** Rascunho — **este documento se completa com entrevistas** · **Última atualização:** 2026-07-27 · **Responsável:** business-analyst
> Aqui vive o conhecimento do NEGÓCIO (não do software). O que está confirmado vem das instruções do projeto e do sistema legado; o restante está explicitamente marcado como a descobrir.

> ✅ **Corrigido em 2026-07-27** — este documento já reflete a premissa
> correta (a Dona Arteira **pinta**, não funde). Ver
> [ADR-0023](../27-ADR/ADR-0023-producao-e-pintura-nao-fundicao.md)
> (produção é pintura) e [ADR-0024](../27-ADR/ADR-0024-quarentena-de-secagem.md)
> (quarentena de secagem no recebimento).

## 1. O negócio (confirmado)

A Dona Arteira **pinta artesanalmente e comercializa** peças decorativas
em gesso. Compra as peças **prontas mas cruas** (sem pintura, e nem sempre
secas) e as **pinta à mão**. Processo real: compra de peça crua →
recebimento (peça úmida) → **quarentena de secagem** → liberação →
**pintura manual** → acabamento → controle de qualidade → estoque → venda →
separação → embalagem → expedição → NF-e → financeiro. Não há fundição,
moldes nem consumo de gesso para moldar ([ADR-0023](../27-ADR/ADR-0023-producao-e-pintura-nao-fundicao.md)).

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
| Gesso é frágil | perdas por quebra em recebimento/secagem, pintura, manuseio e transporte são evento normal → registro de perdas em todo o fluxo (recebimento/secagem: BR-405; produção: BR-104) + embalagem cuidadosa (checklist) |
| Pintura manual | peças "iguais" variam; tempo de pintura é gargalo; produtividade por pessoa interessa; minutos de bancada entram no custo (BR-108) |
| Secagem depende do clima | lead time variável → datas prometidas com folga; `drying_days` por peça é estimativa, não contrato |
| Peça crua chega úmida do fornecedor | entra em **quarentena de secagem** no recebimento; indisponível para pintar até a **liberação** (BR-404, [ADR-0024](../27-ADR/ADR-0024-quarentena-de-secagem.md)) |
| Produção em lotes pequenos | OPs de poucas dezenas; UX de apontamento deve ser leve |
| Sazonalidade provável (datas comemorativas) | picos de venda → buffer de estoque no site, proibição de cutover em nov/dez, planejamento de produção antecipado |

## 3. Roteiro de descoberta (entrevistas obrigatórias antes dos Gates 02–03)

### Produção — pintura (com quem pinta)
1. Quais as etapas reais e sua ordem (pintura → acabamento → CQ)? Alguma peça pula etapas?
2. Quantos dias de secagem por tipo/tamanho de peça? Varia com a estação? **Como sabem que a peça secou e pode ser liberada para pintura** (BR-404)?
3. Quem pinta o quê? Quantas pessoas pintam? Há especialização por cor/técnica?
4. % típico de quebra por etapa (recebimento/secagem, pintura, acabamento, CQ)? Onde dói mais?
5. Tempo médio de pintura por peça/cor (minutos de bancada)? Medem isso hoje? — insumo do custeio (BR-108).
6. Receita de pintura (ficha técnica): existe anotada? Quais tintas/vernizes e quanto por peça/cor?
7. Pinta-se para estoque, sob encomenda, ou misto? Como decidem o que pintar na semana?
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
