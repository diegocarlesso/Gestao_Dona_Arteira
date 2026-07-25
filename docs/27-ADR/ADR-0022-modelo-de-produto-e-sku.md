# ADR-0022: Modelo de produto — variação é produto próprio, SKU sequencial neutro

> **Status:** ✅ **Aceito 2026-07-25** (dono) · **Data:** 2026-07-25 · **Decisores:** dono, chief-architect
>
> 📌 **Correção de premissa — 2026-07-25, poucas horas após o aceite.**
> Este ADR foi escrito supondo que o banco do desktop existia com dados e
> apenas não estava acessível. **O dono esclareceu que o sistema desktop
> nunca chegou a ser alimentado: ele nunca entrou em operação, e não há
> dado algum nele.** As duas decisões abaixo **não mudam** — na verdade
> ficam mais firmes, porque a Alternativa C (reaproveitar `pieces.code`)
> deixa de ser "indisponível" e passa a ser **impossível**: não existem
> códigos para reaproveitar. O texto original fica preservado; o que
> muda de fato está marcado com 📌 ao longo do documento. Um ADR aceito
> não se reescreve (regra 3 da [pasta 27](README.md)), mas também não
> pode seguir afirmando um fato falso.
> **Módulos afetados:** 32 (Catálogo), 09 (Estoque), 10 (Vendas), 16 (WooCommerce), 17 (Migração), 04 (Banco)
> **Regras:** [BR-002](../01-Regras-de-Negocio/01-registro-de-regras.md) (SKU único e imutável), BR-003 (varejo/atacado), BR-007 (categorias)

## Contexto

O módulo de catálogo é o primeiro do Gate 01 depois do Identity, e é
pré-requisito de estoque (09), vendas (10) e da migração (17) — nada
casa sem uma chave de produto.

Duas perguntas estavam explicitamente **em aberto** no
[modelo conceitual](../04-Banco-de-Dados/01-modelo-conceitual.md#perguntas-em-aberto),
com a instrução de "decidir no Gate 01 com dados reais". Os dados reais
chegaram com o [inventário do legado](../31-Inventario-Legado/02-produtos.md),
e são estes:

| Fato | Número | Origem |
|---|---:|---|
| Produtos publicados no WooCommerce | 716 | [31/02 §2](../31-Inventario-Legado/02-produtos.md) |
| — simples | 677 | idem |
| — variáveis | 39 | idem |
| Variações (`product_variation`) | 77 | idem |
| **Produtos com SKU** | **0** | [31/02 §3.1](../31-Inventario-Legado/02-produtos.md) |
| Variações com SKU | 0 | idem |
| Produtos de título duplicado | 37 (14 grupos) | [31/02 §7](../31-Inventario-Legado/02-produtos.md) |
| Cores no eixo `pa_cor` | 39 | [31/04 §3](../31-Inventario-Legado/04-atributos.md) |
| Faixas no eixo `pa_altura` | 7 | [31/04 §4](../31-Inventario-Legado/04-atributos.md) |
| Produtos em KITS/TRIOS (compostos) | 213 (~30%) | [31/02 §8](../31-Inventario-Legado/02-produtos.md) |

**O SKU não existe hoje, em lugar nenhum do site.** A BR-002 exige código
único e **imutável após criação**; o sistema desktop tem `pieces.code`
cumprindo esse papel, o WooCommerce não tem nada. Logo, o ERP não
*importa* SKUs: ele os **cria**, para 716 produtos de uma vez, e essa
criação é irreversível por definição da própria regra.

**A cor não é um rótulo, é um processo.** As 39 cores descrevem o
acabamento de **pintura manual** — vocabulário do ateliê ("bronze
velho", "marsala dourada"). Cada acabamento é uma etapa de produção
distinta, consome tintas distintas e resulta numa peça física distinta
na prateleira.

**Restrição de dados conhecida:** o dump do banco do desktop
(`dona_arteira`) **não está disponível** — é a pendência RC-02 da
[pasta 31](../31-Inventario-Legado/15-recomendacoes.md), classificada
como risco alto. O WooCommerce guarda **apenas preço de varejo**; o
preço de atacado (BR-003) só existe no desktop. O dono confirmou em
2026-07-25 que não tem acesso ao dump por ora, e decidiu seguir sem ele.

## Decisão

**1. Cada variação é um produto próprio, com SKU próprio.** Não há
tabela de variações nem atributos variantes: "Buda sidarta 11 cm azul" e
"Buda sidarta 11 cm dourado" são duas linhas em `products`, cada uma com
seu SKU, seu saldo, seu custo e seu preço. Cor e altura viram atributos
descritivos da linha, não eixos de um produto-pai.

**2. O SKU é sequencial e sem significado: `DA-0001`.** Prefixo fixo
`DA-`, contador com zero à esquerda em quatro dígitos, crescendo para
cinco quando passar de 9999. Gerado pelo ERP na criação do produto,
**imutável** (BR-002), único no catálogo inteiro — peças, matéria-prima,
embalagem e revenda dividem a mesma sequência, porque dividem a mesma
tabela ([BR-207](../01-Regras-de-Negocio/01-registro-de-regras.md), um
só estoque).

## Alternativas consideradas

### Variação — Alternativa A: um produto com atributos (cor, altura)
Um "Buda sidarta 11 cm" com atributos multivalorados, no formato do
WooCommerce.

**Prós:** catálogo menor (716 linhas em vez de ~793); espelha
diretamente a estrutura do Woo, simplificando a sincronização da
pasta 16; cadastrar uma cor nova não cria produto.
**Contras:** o estoque deixa de saber quantos budas **azuis** existem —
e o saldo por acabamento é a informação que a operação precisa, porque é
o que está na prateleira. Recuperar isso exigiria uma tabela de saldo por
combinação de atributos, que é a tabela de variações de volta, com outro
nome e sem SKU. O custo médio ([ADR-0008](ADR-0008-ledger-estoque.md))
também é por acabamento: dourado consome tinta mais cara que branco.
**Descartada:** economiza linhas no catálogo e paga com a pergunta que o
negócio mais faz ao estoque.

### SKU — Alternativa B: prefixo por categoria (`BUD-0001`, `INC-0042`)
**Prós:** legível de imediato; quem ouve o código sabe do que se trata.
**Contras:** um SKU com significado embutido **mente quando o
significado muda**, e a BR-002 proíbe corrigi-lo. Não é hipótese remota:
30% do catálogo são kits/trios, exatamente a fatia que mais muda de
classificação, e 14 grupos de títulos duplicados vão ser reorganizados
na migração. Cada recategorização deixaria um código errado e permanente.
**Descartada:** a legibilidade é resolvida pelo nome do produto, que
aparece ao lado do SKU em toda tela; a imutabilidade não é resolvível
depois.

### SKU — Alternativa C: reaproveitar `pieces.code` do desktop
**Prós:** preserva os códigos que a operação já decorou; casaria o ERP
com o histórico do desktop sem tradução.
**Contras:** **depende do dump do desktop, que não temos** (RC-02).
Mesmo com ele, cobriria só a parte do catálogo que existe nos dois
sistemas, exigindo um segundo formato para o resto — dois padrões de SKU
convivendo desde o primeiro dia.
**Descartada por indisponibilidade**, não por mérito. Ver gatilho de
revisão.

> 📌 **Correção (2026-07-25):** não é indisponibilidade, é inexistência.
> O desktop nunca foi alimentado — `pieces` está vazia, não há um único
> `code` para reaproveitar. Esta alternativa é **impossível**, não
> adiada, e o gatilho de revisão correspondente foi cancelado abaixo.

## Consequências

**Positivas:**
- O estoque responde "quantos budas azuis de 11 cm existem" sem tabela
  auxiliar — é uma linha de `products` com saldo próprio.
- Custo médio por acabamento sai de graça: cada SKU tem seu próprio custo
  no ledger ([ADR-0008](ADR-0008-ledger-estoque.md)).
- Os 37 produtos de título duplicado deixam de ser um problema e viram a
  solução: são variações que alguém recriou como produto porque o Woo
  tornava isso mais fácil. No ERP, é a forma correta.
- SKU neutro nunca precisa ser corrigido, que é o único jeito de uma
  chave imutável continuar verdadeira.
- Um só formato de SKU para o catálogo inteiro, sem exceções a explicar.

**Negativas / dívidas assumidas:**
- O catálogo cresce de 716 para aproximadamente **793 linhas** (716 − 39
  pais + 77 variações). Cadastro mais trabalhoso: uma peça em cinco cores
  são cinco cadastros. Mitigável depois com "duplicar produto" na tela,
  que **não** é a mesma coisa que um produto-pai — é conveniência de
  digitação, não estrutura.
- **O SKU não é legível por humano.** `DA-0413` não diz nada sozinho.
  Toda tela que mostra SKU precisa mostrar o nome junto, e a busca precisa
  aceitar nome, não só código. É custo de UI, permanente.
- A sincronização com o WooCommerce ([pasta 16](../16-WooCommerce/README.md))
  ganha uma tradução: o Woo pensa em produto variável com variações, o
  ERP em produtos irmãos. Publicar de volta um "produto variável" exigirá
  agrupar por algum critério — provavelmente um campo de agrupamento
  visual, a decidir na pasta 16, **não** um produto-pai no domínio.
- **O preço de atacado nasce vazio.** A BR-003 diz que todo produto tem
  dois preços; sem o dump do desktop não há de onde tirar o segundo. O
  campo existe e aceita nulo até ser preenchido — ou seja, a BR-003 fica
  *estruturalmente* atendida e *factualmente* pendente. Registrado como
  pendência aberta na [pasta 32](../32-Catalogo/README.md).
- Numeração sequencial revela o tamanho do catálogo a quem vê um SKU.
  Aceito: é catálogo público de peças decorativas, não há o que proteger
  aqui — diferente do `public_id` dos usuários, que é ULID por isso mesmo.

**Gatilhos de revisão:**
- ~~O dump do desktop aparecer → reabrir a Alternativa C~~
  📌 **Gatilho cancelado em 2026-07-25:** o desktop nunca foi alimentado,
  então não há dump que possa aparecer nem código legado a preservar. O
  ERP é a **primeira** origem de SKU que a empresa terá — o que também
  significa que ninguém tem código decorado, e a legibilidade do SKU
  importa menos do que este ADR supunha ao pesar a Alternativa B.
- Cadastro em lote de variações se mostrar penoso na prática (relato da
  operação, não suposição) → avaliar "duplicar produto" ou importação por
  planilha na tela de catálogo. Não muda o modelo.
- O contador passar de 9999 → largura vai a cinco dígitos sem quebrar os
  SKUs existentes, porque o prefixo é fixo e a comparação é textual.
- A pasta 16 concluir que o Woo precisa de produto variável de verdade
  para exibição → decidir lá o agrupamento visual; **não** reintroduzir
  produto-pai no domínio sem novo ADR.
