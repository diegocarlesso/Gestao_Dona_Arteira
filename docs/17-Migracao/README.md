# 17 — Migração de Dados

> **Status:** Em revisão · **Última atualização:** 2026-08-11 · **Responsável:** migration-specialist
> **Regras:** BR-706, BR-707 · **Fase:** Gate 01 (executada antes do Gate 02) · **ADR:** [0010](../27-ADR/ADR-0010-migracao-etl.md) · **Documentos:** [Plano de cutover](01-plano-de-cutover.md)

## 1. Objetivo

Levar para o ERP o patrimônio de dados da empresa — produtos, categorias, clientes, pedidos históricos, imagens (referências), estoque — **a partir de uma única fonte: o WooCommerce** (via REST API). Após o cutover, o ERP controla os dados e o Woo apenas sincroniza.

> 📌 **Corrigido em 2026-07-25.** Este documento dizia "**duas fontes**:
> WooCommerce e banco MySQL do sistema desktop legado". O dono esclareceu
> que **o desktop nunca chegou a ser alimentado** — nunca entrou em
> operação, e não há dado algum nele. O que existe no repositório é o
> *código* do sistema, útil como referência de regras de negócio
> pretendidas, não como origem de dados.
>
> A migração fica **mais simples** do que o planejado: uma fonte, sem
> deduplicação cruzada entre sistemas e sem reconciliação de códigos. Em
> compensação, tudo que só existiria no desktop — clientes de balcão,
> pedidos de atacado, preços de atacado — **não existe em lugar nenhum**
> e terá de ser cadastrado do zero, se a empresa o praticar.

## 2. Princípios (BR-706, ADR-0010)

1. **Idempotente e re-executável**: rodar duas vezes não duplica nada (upsert por chave natural + `integration_mappings`).
2. **Staging isolado**: dados brutos caem em tabelas `stg_*`; só entram no modelo definitivo após saneamento validado.
3. **Dry-run em tudo**: todo comando tem `--dry-run` com relatório do que faria.
4. **Rejeição explícita**: registro que não passa nas regras vai para relatório de rejeições com motivo — nada é descartado silenciosamente.
5. **Auditável**: cada lote tem `import_batch` rastreável; números de controle conferidos (contagens origem × destino).

## 3. Fases

```mermaid
flowchart LR
    A[1. Inventário<br/>volumes, plugins, qualidade] --> B[2. Extração<br/>Woo API → stg_*]
    B --> C[3. Saneamento<br/>dedupe, SKUs, docs, preços]
    C --> D[4. Carga<br/>stg_* → modelo ERP + mappings]
    D --> E[5. Validação<br/>contagens, amostras, centavos]
    E --> F[6. Cutover<br/>congelamento + delta + inventário físico]
    F --> G[7. Operação assistida<br/>reconciliação diária reforçada]
```

### F1 — Inventário (pré-requisito de tudo)
Contagens (produtos, variações, clientes, pedidos por ano), plugins do Woo (mapeamento pasta 16), qualidade: % produtos sem SKU, clientes duplicados por e-mail/doc, pedidos órfãos. Saída: relatório que **recalibra os NFRs** (pasta 03) e dimensiona o esforço de saneamento.

### F2 — Extração ✅ *catálogo implementado em 2026-07-25*
- Woo: paginação via REST (products, categories, customers, orders com `after`), incremental por `modified_after` nas re-execuções.

**Estado:** produtos, categorias e **clientes** prontos —
`php artisan erp:migrate:extract {produtos|categorias|clientes|tudo} [--dry-run] [--pagina=N]`.
Código em `app/Modules/Integrations/WooCommerce/`.

> ✅ **Pedidos históricos, implementado em 2026-08-11 — mas fora deste
> pipeline de 5 fases.** Em vez de um `erp:migrate:extract/triage/load/
> validate pedidos` novo, a carga histórica reaproveita o comando
> síncrono que já sincroniza pedidos em produção
> (`erp:woo:pull-orders`, módulo `Integrations/WooCommerce`) com a nova
> flag `--historico`: `php artisan erp:woo:pull-orders --status=completed,processing,on-hold,cancelled --historico [--dry-run] [--after=...]`.
> Sem etapa de triagem humana separada — o mesmo `ImportWooOrder` que já
> roda para o webhook em tempo real processa cada pedido, com
> idempotência por `integration_mappings` (repetir a puxada não
> duplica). Ver [docs/16-WooCommerce/01-mapeamento-de-campos.md](../16-WooCommerce/01-mapeamento-de-campos.md#pedido)
> para o que é capturado (itens, endereço de entrega/cobrança, nota do
> cliente, forma de entrega, status).

> ⚠️ **`tudo` não inclui clientes**, e isso é decisão (2026-08-10):
> extrair pessoas é copiar dado pessoal para dentro do ERP (pasta 25 §3),
> e tem de ser ato deliberado escrito na linha de comando — não efeito
> colateral de quem só queria reextrair o catálogo. Ver [§ Clientes](#clientes-f2f5).

Três coisas que a implementação obrigou a decidir:

- **As variações não vêm em `/products`.** O Woo as expõe por produto
  pai (`/products/{id}/variations`), então a extração faz uma chamada
  extra por produto variável — 39 no catálogo atual. Sem isso perderíamos
  as 77 variações, que pelo [ADR-0022](../27-ADR/ADR-0022-modelo-de-produto-e-sku.md)
  são produtos de pleno direito no ERP.
- **SKU vazio vira NULL** no staging. A origem manda string vazia em 716
  de 716; guardar como veio faria a triagem contar com `where('sku','')`,
  que é a pergunta certa escrita do jeito errado.
- **Trava de paginação.** A parada normal é a página vir incompleta, o
  que confia no servidor se comportar — e do outro lado há um WordPress
  com plugins não auditados. Um endpoint que ignore `page` giraria até
  estourar a memória; o limite (`WOO_MAX_PAGES`, mil por padrão) falha com
  mensagem em vez de travar. Não é hipótese: aconteceu no desenvolvimento
  e derrubou a suíte de testes.

**Credenciais:** `WOO_ENABLED`, `WOO_URL`, `WOO_KEY`, `WOO_SECRET` no
`.env` (ver `.env.example`). Permissão de **leitura** basta. A integração
nasce desligada de propósito.

#### Resultado da primeira extração real — 2026-07-27, produção

Rodada em produção (é lá que a carga vai acontecer, então é lá que o
staging precisa estar). 15 páginas de produtos, sem falha, precedida de
`--dry-run` com os mesmos números.

| Métrica | [Inventário](../31-Inventario-Legado/02-produtos.md) | Extraído | |
|---|---:|---:|:-:|
| `simple` | 677 | 677 | ✅ |
| `variable` | 39 | 39 | ✅ |
| `variation` | 77 | 77 | ✅ |
| **Total no staging** | — | **793** | |
| Sem SKU | 716 de 716 | 793 de 793 | ✅ |
| Sem peso | 46 | 46 | ✅ |
| Categorias | — | **48** | 🆕 |

**A conferência bateu em todas as linhas.** É a validação da F5 feita já
na F2: se a extração fosse infiel, seria aqui que apareceria.

**754 dos 793 têm preço** — e esse número não é coincidência. Os 39
`variable` são invólucros: no Woo o preço mora nas variações, não no pai.
Ou seja, os itens com preço são exatamente os que viram produto no ERP
(677 simples + 77 variações = **754**), e os 39 pais são descartados na
carga por não terem correspondente no nosso modelo
([ADR-0022](../27-ADR/ADR-0022-modelo-de-produto-e-sku.md)).

> 📌 **Correção de número:** o ADR-0022 estimou "aproximadamente 793
> linhas" para o catálogo do ERP, mas a fórmula que ele mesmo enuncia
> (716 − 39 + 77) dá **754** — e a medição confirma 754. O 793 é o total
> do *staging*, que guarda também os 39 invólucros. A decisão não muda;
> só o tamanho esperado do catálogo, para menos.
- ~~Legado: leitura direta do MySQL do desktop → `stg_legacy_*`~~ — **não se aplica** (2026-07-25): o desktop nunca foi alimentado. Não há `stg_legacy_*`, nem a exceção ao BR-701 que este item abria.

### F3 — Saneamento (onde a migração é ganha ou perdida)

> ✅ **Catálogo triado em 2026-07-27** —
> `php artisan erp:migrate:triage [--duplicados]`. Classifica o staging e
> propõe SKU e nome. **Nada é carregado**: a F4 só aceita o que estiver
> aprovado, porque a BR-002 torna o código imutável e errar ali é errar
> para sempre.

#### O que os dados contrariaram

A pasta 31 §04 diz que o eixo de variação é **cor e altura**. **Não é.**
Das 77 variações: **56 são composição de kit** (`pa_peca-kit-1`, com
preços e pesos próprios — 89,90 / 99,90 / 169,90) e **21 estão vazias**,
de anúncios que declaram cor como eixo e nunca materializaram as
variações. **Zero variações por cor.**

A cor existe, mas como atributo do próprio produto: 614 dos 716 a
declaram, quase todos com uma cor só — e esses já são "um produto, uma
cor", que é o que o [ADR-0022](../27-ADR/ADR-0022-modelo-de-produto-e-sku.md)
queria. O problema está nos ~20 anúncios "várias cores", que listam de 2
a 5 num produto só.

**Decisão do dono (2026-07-27):** nesses anúncios, **um anúncio vira um
produto**, com a cor como texto. Expandir cada cor em produto próprio só
faria sentido se a operação mantivesse peças pintadas em estoque.

#### A classificação não é uniforme por tipo do Woo

| Origem | Destino | Qtd |
|---|---|---:|
| `simple` | produto | 677 |
| `variable` cuja única variação é vazia | **o anúncio** vira produto | 21 |
| `variable` com variações reais | invólucro — descartado | 18 |
| `variation` com atributo | produto | 56 |
| `variation` vazia | descartada — o pai virou o produto | 21 |
| | **total no catálogo** | **754** |

Um `variable` vira produto **ou** é descartado conforme as variações dele
existirem de verdade. A regra da última linha existe porque é o pai que
carrega nome e anúncio; a variação vazia só empresta o preço.

#### Duas correções que só apareceram ao rodar

- **As variações de kit não herdam o nome do pai** — recebem o rótulo da
  opção. Carregado como veio, o catálogo teria 18 produtos chamados "KIT
  COMPLETO" e 11 "BUDA SIDARTA", cada um de um kit diferente, e a tela de
  venda mostraria linhas idênticas. A triagem compõe:
  `<peça> — KIT COMPLETO`, com as duas partes vindas da origem.
- **O relatório inflava o trabalho manual**, contando essas variações
  como títulos repetidos: acusava 88 produtos em 21 grupos quando a
  duplicata real são **37 em 14** — o mesmo número que o inventário
  achara por conta própria. Relatório que pede decisão humana onde não há
  destrói a confiança na ferramenta.

#### O que ainda espera decisão

| Pendência | Itens |
|---|---:|
| Anúncios repetidos (mesma peça recriada com cores diferentes) | 37 em 14 grupos |
| Sem peso | 35 |
| Sem preço | 21 |

SKUs propostos: `DA-0001` … `DA-0754`, em ordem estável de `woo_id` —
rodar a triagem de novo propõe os mesmos códigos, senão a conferência já
feita viraria lixo.
| Problema esperado | Tratamento |
|---|---|
| Produto sem SKU no Woo | gerar SKU proposto → **aprovação humana** em lote |
| Mesmo produto nas duas fontes | casar por SKU/nome; legado ganha em dados físicos (dimensões/embalagem), Woo em conteúdo (descrição/imagens) |
| Cliente duplicado (e-mail/doc) | merge com regra: doc válido > e-mail > mais recente; histórico consolidado |
| CPF/CNPJ inválido | manter cliente, marcar pendência fiscal (bloqueia NF-e futura, não a migração) |
| Preço float com dízima | arredondamento + relatório de diferenças > R$ 0,01 |
| Categoria órfã/duplicada | árvore consolidada com de-para |

### F4 — Carga ✅ *catálogo carregado em 2026-07-27, produção*

`erp:migrate:approve [--completos] [--sku=] [--desfazer]` → `erp:migrate:load`.
Idempotente por `integration_mappings` (BR-704/BR-706): recarregar
atualiza, então corrigir o staging não exige limpar o catálogo.

| Resultado | |
|---|---:|
| Produtos no catálogo | **754** |
| Categorias | 48 |
| Preços de varejo | 733 |
| Sem peso | 38 |
| Sem preço de varejo | 21 |
| Sem preço de atacado | 754 |

Os 754 SKUs `DA-0001 … DA-0754` foram aprovados pelo dono antes da carga
e são **imutáveis** a partir daqui (BR-002).

#### Três conversões que os dados obrigaram

- **Peso: quilos → gramas.** O Woo guarda em kg, o ERP em g. Sem
  converter, uma peça de 450 g entraria pesando meio grama.
- **Peso implausível recusado.** Três produtos trazem `970` num campo em
  quilos — gramas digitadas no lugar errado. Carregar 970 kg produziria
  frete absurdo **em silêncio**; o peso fica nulo e o produto aparece no
  relatório de cadastro incompleto. Daí 38 sem peso, e não 35: 35 vieram
  vazios da origem e 3 foram recusados aqui.
- **Cor vem em dois formatos.** O produto lista as possíveis em `options`
  (plural); a variação traz a escolhida em `option` (singular). Ler só um
  perderia metade dos casos.

#### O que o catálogo herdou, e é decisão humana resolver

Os 37 anúncios repetidos entraram como 37 produtos, conforme a decisão de
2026-07-27 (um anúncio, um produto). O par mais próximo é `DA-0001` e
`DA-0008` — o mesmo trio, na mesma cor (*rosa e capuccino*), na mesma
categoria `Trio da Sabedoria`. Fundir é arquivar o repetido, e o
relatório "produtos com nome repetido" ([pasta 20 §3.1](../20-Relatorios/README.md))
existe para achá-los.

> 📌 **Correção de 2026-07-27.** Este parágrafo dizia `DA-0001` e
> `DA-0002`, "em categorias diferentes". Os dois números estavam errados:
> `DA-0002` é o mesmo trio em **outra cor** (*rosa e preto*), não uma
> duplicata, e a duplicata real de `DA-0001` está na **mesma** categoria.
> Os SKUs saem em ordem de `woo_id`, e os dois anúncios repetidos são
> 1177 e 1201 — oito posições de distância, não uma.

Onde a duplicata se concentra, medido na origem:

| Categoria | Grupos | Produtos |
|---|---:|---:|
| Trio da Sabedoria | 7 | 16 |
| Incensários Cascata | 4 | 13 |
| Incensários Vareta | 1 | 3 |
| BUDAS | 1 | 3 |
| ELEFANTES | 1 | 2 |
| **total** | **14** | **37** |

**Todos os 14 grupos duplicam dentro da mesma categoria** — nenhum está
partido entre duas, o que torna a conferência uma comparação de fotos
lado a lado dentro de uma lista só.

#### A categoria canônica saiu errada em 34 produtos — corrigido em 2026-07-27

A primeira carga tomava a **primeira** categoria que a API listasse, e a
API não ordena por relevância. Medido em produção depois da carga:

| Categoria no ERP | Produtos | O que ela é de verdade |
|---|---:|---|
| DIA DAS MÃES | 15 | campanha sazonal |
| Home Kits | 8 | bloco da home |
| Home Trios Da Sabedoria | 8 | bloco da home |
| Home Lançamentos | 2 | bloco da home |
| HOME SITE | 1 | raiz da vitrine |
| **total** | **34** | |

É o [RC-06](../31-Inventario-Legado/15-recomendacoes.md) entrando pela
porta dos fundos: o inventário previu que merchandising contaminaria a
árvore, e a carga não filtrou. A regra de escolha passou a ser a da
[pasta 32 §3.4](../32-Catalogo/README.md) — descarta vitrine, prefere a
mais profunda. Nenhum dos 34 fica órfão: todos têm categoria temática
disponível na origem.

### F4 — Carga (planejamento original)
Ordem por dependência: categorias → produtos+imagens(refs)+preços → clientes+endereços → pedidos históricos (com snapshot de preço original; itens de produto extinto apontam para produto `archived`) → financeiro histórico **não** é migrado (somente pedidos; saldo financeiro abre zerado no Gate 04 — decisão registrada).

---

## Clientes (F2…F5)

> ✅ **Implementado em 2026-08-10.** Fecha o item que faltava da F2:
> "clientes e pedidos ficam para quando os módulos correspondentes
> existirem" — Vendas existe desde o Gate 02. Pedidos históricos foram
> implementados em seguida, em 2026-08-11 — ver [§ Pedidos históricos](#pedidos-históricos).

### A decisão de escopo: 62, não 198

**Migram só os clientes com pedido real** — 62 dos 198 cadastros do site
([pasta 31 §2](../31-Inventario-Legado/06-clientes.md)). Os ~136 sem
nenhuma compra ficam de fora por **minimização de dados pessoais**
(LGPD, [pasta 25 §3](../25-Seguranca/README.md)): são checkout
abandonado, newsletter e importação antiga, e guardá-los no ERP seria
reter dado de gente sem finalidade de venda nem obrigação fiscal.
Decisão da diretoria em 2026-08-10.

O critério é o `orders_count` que a própria API do Woo devolve no objeto
do cliente, e ele é aplicado **na triagem, não na extração**: a extração
traz tudo "como é, sem julgar", como no catálogo. Filtrar antes
economizaria linhas de staging e custaria a prestação de contas —
ninguém conseguiria dizer depois quantos ficaram de fora e por quê
(princípio 4).

### F2 — Extração

```
php artisan erp:migrate:extract clientes [--dry-run] [--pagina=N]
```

Grava em `stg_woo_customers` (mesma convenção do `stg_woo_products`:
colunas espelhando a origem + `payload` com o JSON inteiro, inclusive
`billing`, `shipping` e `meta_data`). Idempotente por `woo_id`.

Três coisas que a implementação obrigou a decidir:

- **`tudo` não inclui clientes.** Ver o aviso na F2 acima.
- **Sem `role=all`.** O endpoint `/customers` devolve, por padrão, só
  quem tem papel `customer`. Os outros 2 usuários do site são
  administradores do WordPress (pasta 31 §2) — trazê-los seria copiar
  dado pessoal de quem nunca comprou.
- **`orders_count` ausente ≠ zero.** A coluna é nulável: zero rejeita,
  nulo fica **pendente de decisão humana**. Rejeitar por dado ausente
  descartaria um comprador em silêncio.

O relatório imprime os números medidos **ao lado dos do inventário**
(198 cadastros / 62 compradores): é a conferência da F5 feita já na F2,
como aconteceu no catálogo.

### F3 — Triagem (onde a rejeição é o produto principal)

```
php artisan erp:migrate:triage clientes [--rejeitados]
```

No catálogo a triagem existia para *propor* SKU; aqui ela existe para
*recusar*. Destinos (`DestinoDoCliente`):

| Destino | O que é |
|---|---|
| `cliente` | comprou pelo menos uma vez — migra |
| `sem_pedido` | cadastro sem compra — **não migra** (LGPD) |
| `duplicado` | segunda conta da mesma pessoa no próprio site |
| `pending` | origem não trouxe `orders_count` — decisão humana |

Propõe também o **nome** (`first_name + last_name`, com recuo para os
nomes da cobrança e, por fim, para `Cliente do site` — o **mesmo** texto
que o `ResolveCustomerService` já usa; dois rótulos para a mesma lacuna
fariam a operação achar que são duas situações) e o **documento**,
garimpado nos metadados do plugin brasileiro (`_billing_cpf`,
`billing_cpf`, `_billing_cnpj`, `billing_cnpj` — a mesma varredura que o
`ImportWooOrder` resolveu empiricamente).

Duplicata resolve pela regra desta pasta — **doc válido > e-mail > mais
recente**. O inventário mediu **zero** duplicatas entre os 62
compradores (§7: CPF e e-mail batem 1:1), então o esperado é não marcar
nada; o código existe porque a alternativa é descobrir a duplicata como
violação do UNIQUE de `customers.doc` no meio da carga.

`--rejeitados` lista quem não migra, com o motivo e o **e-mail
mascarado** (`m***a@gmail.com`): o motivo de não migrá-los é justamente
não espalhar o dado deles, e o `woo_id` já identifica o cadastro no site.

**Não há `erp:migrate:approve clientes`.** A aprovação existe no
catálogo porque o SKU é imutável (BR-002) e errar ali é errar para
sempre; aqui nada imutável é cunhado. A revisão humana é o `--dry-run`
da carga, que imprime exatamente o que ela faria.

### F4 — Carga

```
php artisan erp:migrate:load clientes --dry-run   # revisar primeiro
php artisan erp:migrate:load clientes
```

**O que o catálogo não tinha: o destino já está povoado.** Quando
`products` foi migrada, a tabela estava vazia. Aqui não — desde o Gate
02, todo pedido do site chama o `ResolveCustomerService`, que **já cria
o cliente** (origem Woo) e **já grava o mapeamento** quando o comprador
tem conta no Woo. Só nunca grava endereço. Daí três situações:

| Situação | Tratamento |
|---|---|
| `integration_mappings` já existe | **enriquece**: grava os endereços que faltam e completa campos vazios. Não cria um segundo. |
| Sem mapping, mas e-mail/doc casa com cliente do ERP | **merge** (doc válido > e-mail > mais recente): o mapping é ligado ao cadastro existente e o merge aparece no relatório — "dois viraram um" é informação que a operação precisa ver. |
| Nenhum dos dois | cria `Customer` (origem Woo) + `CustomerAddress` + mapping. |

Quatro decisões que a implementação obrigou:

- **Completa lacuna, não sobrescreve decisão.** Campo vazio no ERP
  recebe o valor do site; campo já preenchido fica como está e a
  diferença vai para o relatório em vez de sumir. É a regra 5 do projeto
  ("conflito resolve-se a favor do ERP") e protege a correção que alguém
  já tenha feito à mão. **Exceção:** o nome-recuo `Cliente do site`, que
  é a marca de "não sabemos" — preservá-lo diante do nome real seria
  preservar a lacuna.
- **Cobrança e entrega iguais viram UM registro com os dois selos.** É o
  caso mais comum (o checkout marca "entregar no endereço de cobrança" e
  devolve os dois blocos idênticos). Dois registros gêmeos fariam a tela
  pedir uma escolha sem conteúdo entre linhas iguais, e o
  `CustomerAddress::saved` já garante um padrão de cada tipo por cliente.
  Endereços diferentes viram dois registros.
- **Endereço sem rua, cidade ou UF de 2 letras não vira registro.** Não
  cota frete, não vira destinatário de NF-e e ocuparia a tela fingindo
  ser dado. Fica de fora com o motivo no relatório; o cliente migra
  assim mesmo e aparece com a pendência "sem endereço". O **número** vem
  do campo do plugin brasileiro (`_billing_number`), nunca de heurística
  sobre `address_1`: separar "Rua X, 123" acerta na maioria e erra calado
  no resto, e endereço errado só aparece quando a encomenda volta.
- **Documento que já pertence a outro cliente não estoura a carga.**
  `customers.doc` é único e o soft delete não libera o número. A pessoa
  entra **sem documento**, com pendência fiscal visível (BR-001) e a
  recusa escrita no relatório — perder o cliente e os endereços por causa
  de um campo seria pior.

O `--dry-run` roda **o mesmo caminho de decisão** da carga real, sem
`save()`: um dry-run que reimplementasse as regras prometeria uma coisa e
faria outra no dia seguinte. Há teste comparando os dois relatórios
campo a campo.

`is_wholesale` é sempre `false` — a pasta 31 §3 confirmou 100% PF/varejo
no canal.

#### Passo obrigatório depois da carga: código IBGE do município

Os endereços migrados nascem com `city_code` **nulo**, e isso é o
desenho, não uma lacuna: o [ADR-0026](../27-ADR/ADR-0026-codigo-ibge-municipio.md)
decidiu que a importação do Woo não resolve o município um a um no meio
da carga — resolve-se depois, num lote que uma pessoa revisa. Sem o
`cMun`, o `SpedNfeGateway` recusa emitir NF-e para o cliente.

```
php artisan erp:enderecos:resolver-ibge              # só relata
php artisan erp:enderecos:resolver-ibge --gravar     # grava
```

O comando é idempotente (só toca em quem está nulo) e lista os pendentes
com cidade e UF, para conferência.

### F5 — Validação

```
php artisan erp:migrate:validate clientes [--amostra=20]
```

Contagens (triados × mapeados) + amostra estável de 20 clientes
conferida campo a campo (nome · e-mail · telefone · documento ·
endereços), com o esperado **re-derivado da origem por implementação
independente** da carga — se chamasse os métodos de `LoadWooCustomers`,
um bug de leitura passaria nos dois lados.

Duas listas por cliente, porque nem toda diferença é erro:

- **divergências** → a migração está errada (cliente sumido, lacuna que
  deveria ter sido preenchida, endereço que não bate, marca de atacado).
  Fazem o comando sair com erro.
- **preservados** → o ERP já tinha valor próprio e ele venceu. Não é
  erro; é o que a operação olha para decidir qual dos dois está certo.

O comando também **falha** se sobrar cadastro em `pending`: linha
pendente não carrega e não aparece em contagem nenhuma — sumiria calada,
e a assinatura cobriria um número que ignora gente. E falha com zero
triados, pelo mesmo motivo do catálogo: "0 = 0" batendo mandaria assinar
sobre o nada, e o engano provável é rodar no banco local.

`mapeados_total` sai separado e **pode ser maior** que os triados sem que
isso seja erro: o pedido do site mapeia o comprador desde o Gate 02, e
quem apagou a conta no Woo depois de comprar não está mais no staging.

### F5 — Validação ✅ *ferramenta pronta em 2026-07-27*

`php artisan erp:migrate:validate [--amostra=30]`. Confere o catálogo
contra a origem em duas frentes e **sai com erro** se algo divergir, para
não passar batido:

1. **Contagens** — aprovados na triagem × mapeados no catálogo. Têm de
   ser iguais.
2. **Amostra estável, campo a campo** — nome, preço de varejo, peso, cor
   e categoria. O esperado é **re-derivado da origem por uma
   implementação independente** da carga: se a validação chamasse os
   mesmos métodos de `LoadWooCatalog`, um bug de conversão passaria nos
   dois lados e a conferência daria verde sobre erro. Cada linha traz o
   `woo #id` para conferência a olho no site.

A amostra é **determinística** (espaçada por SKU): rodar de novo devolve
os mesmos produtos — conferência assinada não depende de sorteio.

> **Cobertura da amostra:** o comando confere o que a carga transformou
> (kg→g, preço, cor, categoria canônica). A conferência de **clientes**
> ganhou comando próprio em 2026-08-10 —
> `erp:migrate:validate clientes`, 20 da amostra do plano original (ver
> [§ Clientes](#clientes-f2f5)). A de **pedidos** e o Σ por ano ficam
> para quando o histórico for migrado.

#### ✅ F5 executada e assinada — 2026-07-28, produção

```
aprovados na triagem ... 754
mapeados no catálogo ... 754   → contagens batem
Amostra estável de 30 produtos, conferida campo a campo:
  30 ✓  ·  0 divergências
```

**Assinada pelo dono (Diego) em 2026-07-28.** A amostra determinística
cobriu os três casos que a triagem criou — produto simples, variação de
kit (`DA-0426 … — KIT COMPLETO`, `DA-0526 … — BUDA SIDARTA`, nome composto
como manda a F3) e anúncio "várias cores" (`DA-0251`) — e nenhum divergiu
da origem. É o **último critério de saída do Gate 01**: dados migrados
validados e assinados.

A fidelidade da extração já fora conferida na F2 (contagens bateram com o
inventário linha a linha); esta foi a conferência humana por cima.

*Critérios originais:* contagens batem (origem × stg × destino) · amostra
conferida manualmente · Σ totais de pedidos por ano batem com relatório
Woo · zero rejeições não-triadas.

### F6 — Cutover → [plano detalhado](01-plano-de-cutover.md)
Inclui **inventário físico completo** para o estoque inicial (nem legado nem Woo são confiáveis — pasta 09) e ativação da sincronização (pasta 16).

## Pedidos históricos

> ✅ **Implementado em 2026-08-11.**

Ao contrário de produtos/categorias/clientes, pedidos **não** ganharam um
pipeline de 5 fases próprio (`erp:migrate:{extract,triage,load,validate}
pedidos`) — decisão explícita, não descuido: o comando síncrono que já
sincroniza pedidos em produção em tempo real (`erp:woo:pull-orders`,
`app/Modules/Integrations/WooCommerce/Console/PullWooOrdersCommand.php`)
já cobre paginação, `--dry-run`, contagem de resultado e idempotência via
`integration_mappings` — construir um staging novo só para pedidos seria
trabalho sem ganho.

O que mudou foi o próprio motor compartilhado (`ImportWooOrder`, usado
tanto pelo webhook em tempo real quanto pela puxada), que passou a
capturar o que faltava — confirmado como lacuna real ao investigar o
pedido #3 (site, chegou sem endereço nem forma de entrega):

- **Endereço de entrega/cobrança do pedido**, gravado em `order_addresses`
  — separado do endereço do cliente, porque a entrega às vezes é para
  outra pessoa (presente). Mesma tradução de campos (`_billing_number`,
  `_billing_neighborhood` etc.) que já roda para o cadastro do cliente.
- **Comentário do cliente** (`customer_note` do Woo → `orders.customer_note`).
- **Forma de entrega** (`shipping_lines[0].method_title` →
  `orders.shipping_method` — o valor já existia em `orders.shipping`).

Uso para a carga real do histórico:
```
php artisan erp:woo:pull-orders \
  --status=completed,processing,on-hold,cancelled \
  --historico --dry-run
```
Revisar o relatório, depois repetir sem `--dry-run`.

**A flag `--historico` só muda o tratamento do status `completed`:** vira
`Entregue` como rótulo documentário, sem lançar reserva nem baixa no
ledger (decisão da diretoria — o Estoque ainda não tem contagem física
calibrada; ver [docs/16-WooCommerce/01-mapeamento-de-campos.md](../16-WooCommerce/01-mapeamento-de-campos.md#status-de-pedido-de-para)
para o detalhe completo). O webhook em tempo real não usa `--historico`
e mantém o comportamento atual intacto.

**Fora de escopo, registrado como pendência:** nota por item do pedido
(`order_items.note`, hoje não preenchido a partir do `meta_data` da
linha), rastreio/transportadora real de expedição refletido
retroativamente a partir do histórico.

## 4. Ferramental

Comandos Artisan documentados pela skill `importador-woocommerce`.
Execução em lotes pequenos retomáveis (limites de shared hosting,
pasta 03). Todos aceitam `{entidade}`, com o **catálogo como padrão** —
os runbooks e a memória da operação registram os comandos sem argumento,
e mudar isso quebraria o que já foi executado:

| Comando | Catálogo | Clientes |
|---|---|---|
| extração | `erp:migrate:extract {produtos\|categorias\|tudo}` | `erp:migrate:extract clientes` |
| triagem | `erp:migrate:triage [--duplicados]` | `erp:migrate:triage clientes [--rejeitados]` |
| aprovação | `erp:migrate:approve [--completos]` | — (não há código imutável a aprovar) |
| carga | `erp:migrate:load` | `erp:migrate:load clientes [--dry-run]` |
| validação | `erp:migrate:validate [--amostra=30]` | `erp:migrate:validate clientes [--amostra=20]` |

## 5. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Qualidade dos dados pior que o esperado | Alta | Alto | F1 mede antes; saneamento com aprovação humana; prazo do Gate 01 assume folga |
| Migrar estoque errado | Certa (se confiar nas fontes) | Alto | Inventário físico no cutover — inegociável |
| Pedidos históricos com produtos deletados no Woo | Alta | Baixo | produto `archived` placeholder preserva o histórico |
| Re-execução duplicar dados | Baixa | Alto | idempotência testada com teste automatizado (rodar 2× no CI) |

## 6. Evoluções futuras

- O mesmo pipeline (stg_* + upsert + relatórios) é reutilizado para futuras importações em massa (tabela de preços, catálogo de marketplace).
