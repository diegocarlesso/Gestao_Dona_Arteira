# Relatório Técnico e Estratégico — ERP Dona Arteira

> **Para:** Claude (agente de implementação) e engenharia
> **De:** Análise CEO / eng. sênior (ERPs, web, integração fiscal/financeira, análise de dados)
> **Data:** 2026-07-27
> **Escopo:** Análise do projeto **real** (`gestao-app`, Laravel 12) — não do protótipo desktop legado. Correções, riscos, recursos e o caminho para um ERP profissional otimizado para o ateliê (pintura artesanal de peças de gesso compradas cruas, nem sempre secas, + outros produtos).
> **Restrições:** Sem ferramentas pagas. Nenhuma linha de código foi alterada nesta análise.
> **Relação com `RELATORIO_ANALISE_CEO.md`:** este relatório é complementar e mais profundo (revisão de código, schema, segurança e sequência de execução), não uma substituição.

---

## 1. Método e evidências

Análise direta do repositório em `D:\Dona_Arteira_Gestão`: código de `gestao-app/app/Modules`, migrations, `composer.json`, testes, histórico git (`git log`), documentação (`docs/`, 22 ADRs) e `.planning/codebase/`. Conclusões abaixo citam arquivos, commits, `BR-xxx` e `ADR-xxxx` para verificação.

---

## 2. Estado real do projeto (correção de registro)

O projeto está **bem mais adiantado** do que a documentação de topo declara. Isso é um risco de governança por si só.

| Fonte | Diz | Realidade (git + código) |
|---|---|---|
| `CLAUDE.md` → "Estado atual" | "Gate 00 concluído. **Existe apenas documentação.**" | Falso hoje. Há app Laravel funcional: módulos **Identity**, **Catalog** e **Integrations/WooCommerce** implementados e testados. |
| `.planning/codebase/STACK.md` (datado 2026-07-06) | "This repository currently contains **no ERP application code**." | Desatualizado. `gestao-app/` foi criado depois; 754 produtos migrados do Woo (commits `b1879cb`, `fb35630`). |

**O que está construído (evidência: `git log`, `app/Modules`, `tests/`):**
- **Identity** (60 arquivos PHP): autenticação (Fortify/Sanctum), **2FA TOTP** (ADR-0021), **RBAC** (spatie/permission, ADR-0011), auditoria (owen-it, ADR-0012), convite de acesso, ciclo de vida de conta, trilha de segurança, política de senha. Coberto por ~13 testes de feature.
- **Catalog** (26 arquivos): `Product` (com `kind`, SKU imutável gerado `DA-0001`, campos fiscais NCM/CEST/origem/GTIN, `drying_days`, `min_stock`), `ProductPrice` (histórico imutável, `retail`/`wholesale`), `ProductImage` (referência à mídia do Woo — ADR-0017), `ProductCategory` (árvore), `Package` (medidas da caixa). Preço como `brick/money` (ADR-0013).
- **Integrations/WooCommerce**: extração → staging (`stg_woo_*`) → triagem com SKU proposto → carga (F2/F3/F4), `integration_mappings`, sem tocar no banco do WordPress (regra 4 do `CLAUDE.md`).
- **Testes**: 30 arquivos (Pest), incluindo testes de arquitetura (`ModulesTest`, `FortifyDesligadoTest`). CI em `.github/workflows/` (lint + tests). PHPStan/Larastan configurado.

**Qualidade do código: alta e consistente.** Schema com `DECIMAL` para dinheiro/dimensões, índices compostos na ordem exata das consultas, FKs com `RESTRICT`/`CASCADE` deliberados, `public_id` ULID como route key, invariantes no `booted()` (SKU imutável em qualquer caminho — seeder/tinker/job), e comentários que explicam o *porquê*. Este é um alicerce que merece ser continuado, não refeito.

---

## 3. Achados críticos — correções e riscos (priorizados)

> Ordenado por severidade. Estes são os itens que eu trataria antes de abrir novos módulos.

### 🔴 CRÍTICO — Credenciais de produção no histórico do git
- **Fato:** o arquivo `/.db` contém, em **texto puro**, o acesso SSH (`u917402451@147.93.39.76:65002`), as senhas dos bancos de **produção** e **staging**, e 8 tokens/segredos. Ele foi **versionado** no commit `e13ae02` e só removido do rastreamento em `4eb9076` ("tira o .db do rastreamento — tinha senha em texto puro").
- **Implicação:** os segredos **continuam recuperáveis no histórico do git**. Estão comprometidos por definição — qualquer clone antigo ou fork os contém.
- **Ação:** **rotacionar já** todas as credenciais expostas (senha SSH/chave, senha do banco de produção `u917402451_da_erp`, staging e os 8 tokens). Depois, decidir entre (a) reescrever o histórico (`git filter-repo`) se o repo for compartilhado, ou (b) aceitar o histórico e confiar na rotação. Guardar segredos só em `.env` do ambiente + cofre (o `.gitignore` já bloqueia `/.db` daqui para frente — isso está correto).

### 🟠 ALTO — Dados pessoais de clientes (LGPD) no working tree e no repo
- `docs/database_dump/u917402451_donaarteira.sql` (441k linhas) é um dump WordPress/WooCommerce **de produção** com PII de clientes (`bwf_wc_customers`, `EWD_OTP_Orders`, `comments`, etc.). Está corretamente **gitignored** hoje, mas vive na árvore de trabalho — risco de commit/sync acidental e de vazamento local.
- **`DADOS DONA ARTEIRA.xlsx` (331 KB) está RASTREADO no git** (o `.gitignore` cobre `*.sql`, não `*.xlsx`). Se contiver CPF/e-mail/telefone/endereço, é **PII em versionamento** (incl. histórico).
- **Ação:** confirmar o conteúdo do `.xlsx`; se tiver PII, removê-lo do rastreamento e adicioná-lo ao `.gitignore`. Manter dumps/planilhas reais fora do repo (cofre/pasta segura); para dev, usar amostra **anonimizada**. Documentar retenção/descarte (LGPD) na pasta `25-Seguranca`/`26-Auditoria`.

### 🟠 ALTO — Documentação de topo desatualizada (drift)
- `CLAUDE.md` ("existe apenas documentação") e `.planning/codebase/*` (2026-07-06, "no application code") contradizem o repositório. Um agente que confie nelas tomará decisões erradas (ex.: recriar o que já existe).
- **Ação:** atualizar `CLAUDE.md` → estado por módulo; **regenerar** `.planning/codebase/` (há skills GSD para isso). Barato e evita retrabalho.

### 🟡 MÉDIO — Risco de N+1 no catálogo (754 produtos e crescendo)
- `Product::precoVigente()`, `imagemPrincipal()` e `pendencias()` disparam consultas **por linha**. Numa listagem/relatório sem eager loading, isso são centenas de queries por página. O `scopeSemPrecoDe` (com `whereDoesntHave`) mitiga um caso, mas a listagem geral e a ficha continuam sensíveis.
- **Ação:** garantir `with(['images','prices','category','defaultPackage'])` nas listagens; para "preço vigente", considerar uma subconsulta/`view` de preço corrente ou pré-cálculo, evitando 1 query por produto. Cobrir com um teste que conte queries (`assertQueryCountLessThan`).

### 🟡 MÉDIO — Módulo de **Clientes** ainda não existe
- `Identity` são **usuários do sistema**, não clientes. Vendas, financeiro e fiscal dependem de um cadastro de cliente (PF/PJ, CPF/CNPJ, endereços, tipo varejo/atacado → puxa a lista de preço certa). Não há tabela `customers`/`clients`.
- **Ação:** é pré-requisito do módulo de Vendas. Portar a validação de CPF/CNPJ do desktop legado (é correta) e o conceito de tipo de cliente (BR-003 já assume atacado+varejo).

### 🟢 BAIXO — Higiene
- Confirmar nível do PHPStan (mirar `level max` incrementalmente) e cobertura mínima no CI.
- `composer.json` com `minimum-stability: dev` — aceitável em pré-1.0, revisar antes de produção.
- Backups automáticos **testados** de produção (Hostinger) — ver §6.

---

## 4. A lacuna central: o domínio ainda não foi construído

O que existe é a **fundação + catálogo**. O coração do ERP do ateliê — **estoque, produção, compras, vendas, financeiro, fiscal** — está desenhado em ADRs mas **não implementado**. E é justamente aqui que mora a especificidade do negócio:

> "As peças são compradas prontas, mas cruas, sem pintura. Nem sempre estão secas, prontas para produção."

O ciclo físico real:

```
COMPRA (fornecedor) → RECEBIDO/ÚMIDO → EM SECAGEM → CRU/SECO (pronto p/ pintar)
        → EM PINTURA (WIP) → ACABADO (vendável) → RESERVADO → VENDIDO
                                   │
                                   └── QUEBRA/PERDA (gesso quebra fácil)
```

O modelo já **antecipa** isso: `Product.drying_days` existe; `kind` distingue matéria-prima/insumo/acabado; **não há coluna de saldo** (saldo vem do ledger, ADR-0008). Falta implementar o ledger e os estados. Diretrizes de design para cada módulo:

### 4.1 Estoque — `inventory_movements` como ledger multi-estado (ADR-0008)
- Todo saldo é **derivado** de movimentos imutáveis (o `CLAUDE.md` regra 7 já exige). Nenhum `UPDATE` direto de quantidade.
- Modelar **localização/estado** por movimento, não só quantidade: `RECEBIDO_UMIDO`, `EM_SECAGEM`, `CRU_SECO`, `EM_PINTURA`, `ACABADO`, `RESERVADO`, `PERDA`. Cada transição é um movimento (com produto, quantidade `DECIMAL(15,3)`, documento de origem, usuário, motivo).
- Quantidade em `finished_good` fica por SKU (cada cor é um produto — BR-009), coerente com o catálogo.
- **Saldo por estado** vira uma consulta/`view` materializada para o painel.

### 4.2 Compras / Recebimento — a "quarentena de secagem"
- Entrada de peça crua gera movimento para `RECEBIDO_UMIDO` + custo unitário (alimenta custeio) + conta a pagar.
- No recebimento, registrar **previsão de liberação** (usar `drying_days` como default, ajustável). Peça úmida **não** entra em `CRU_SECO` até liberação (manual ou por data) — evita prometer prazo com peça que ainda não seca.
- **Rastreabilidade por lote/fornecedor** para medir **taxa de quebra** — insumo de decisão de compra.

### 4.3 Produção — Ordem de Produção (OP) com quebra e custeio de mão de obra
- OP "pintar N un. do produto X": consome `CRU_SECO` (+ insumos: tinta/verniz), produz `ACABADO`, registra **quebras** e **tempo de bancada**.
- **Custeio ABC** (recomendação-chave, ecoando o relatório CEO): custo = peça crua + insumos + **minutos de pintura × custo/hora** + rateio de embalagem. Só assim a margem sobre `retail`/`wholesale` é real. Hoje o custo é invisível.
- **Kanban por estado** (colunas = estados de estoque) é a UI mais intuitiva para o chão do ateliê.

### 4.4 Vendas — reserva de estoque e prazo realista
- Pedido com **status** (`ORÇAMENTO → CONFIRMADO → EM_PRODUÇÃO → PRONTO → ENTREGUE/CANCELADO`).
- Ao confirmar, **reservar** `ACABADO` (move para `RESERVADO`); se faltar, **sugerir/gerar OP** e calcular prazo **incluindo secagem + pintura** — nunca prometer o que a bancada não entrega.
- Preço puxado pela **lista do tipo de cliente** (varejo/atacado, BR-003). Desconto/frete separados do valor pago; pagamentos com status (não um `payment_value` único).
- PDF de orçamento/pedido e link **WhatsApp** (`wa.me`, gratuito).

### 4.5 Financeiro — sem ferramentas pagas
- Contas a receber/pagar, fluxo de caixa, despesas, DRE simples.
- **Boleto (impasse M-01):** manter títulos "a receber" no ERP com **baixa manual/conciliação**; emissão pelo internet banking. Explorar **PIX gratuito PJ** (cobrança via API do banco, se houver, sem custo). Não adotar gateway pago.

### 4.6 Fiscal / NF-e — faseado e gratuito
- Guardar já os campos fiscais no produto (NCM/CEST/origem/GTIN — **já existem**) e mapear regras de operação na pasta 13.
- Emissão quando necessário via **ACBr (ACBrLibNFe/NFCe, LGPL — gratuito)** ou **PyNFe/erpbrasil.edoc**; evitar Focus NFe/eNotas (pagos). Se a empresa for **MEI**, a emissão pode seguir no portal gratuito e o ERP só controla os títulos — validar enquadramento com o contador (pasta `13-Fiscal/01-pauta-validacao-contador.md` já existe).

### 4.7 WooCommerce — sincronização de estoque só de `ACABADO`
- O ERP é SSOT (ADR-0006). Publicar no Woo **apenas o saldo `ACABADO` (menos `RESERVADO`)** de produtos `sell_on_woo`. Peça crua/em secagem/WIP **nunca** vira disponibilidade na loja.
- Sync assíncrona por fila com idempotência (ADR-0007); no Hostinger Business (compartilhado), rodar filas via **Cron + `queue:work --stop-when-empty`** e `schedule:run` (como já apontado no relatório CEO e P-15).

---

## 5. Recursos e ideias que elevam o ERP (otimizado para o ateliê)

- **Painel "chão de ateliê":** o que está secando (com previsão), pronto para pintar, em pintura, acabado abaixo do mínimo. Botões rápidos de transição de estado com foto opcional.
- **Alertas:** lote liberado da secagem; estoque acabado < `min_stock`; secagem vencida; pedido em produção com prazo em risco.
- **Taxa de quebra por fornecedor/lote** → decide de quem comprar.
- **Custeio e margem visíveis** no produto e no pedido (inclui embalagem — peça frágil consome bolha/caixa e "come" margem de itens pequenos).
- **Catálogo com foto** para montar pedido; preço automático por tipo de cliente.
- **Etiquetas de embalagem** (dimensões/peso já cadastrados) e **cotação de frete** a partir da `Package` (Correios/Melhor Envio — há skill `integracao-melhor-envio`; usar plano gratuito).
- **PWA** para uso em tablet no ateliê (câmera para foto da peça, tela cheia).
- **Relatórios/BI grátis:** faturamento por período, ticket médio, margem por produto, giro de estoque, produtividade de pintura (peças/dia), tempo médio de secagem. Metabase (open-source) sobre o MariaDB, ou gráficos in-app.
- **Backups testados:** `mysqldump` agendado (Cron Hostinger) + cópia externa; **testar restauração** periodicamente.

---

## 6. Roadmap sugerido (por Gates, MVP-first)

1. **Antes de novo código (esta semana):** rotacionar segredos expostos; tirar `.xlsx` do git se tiver PII; atualizar `CLAUDE.md` e regenerar `.planning/codebase/`.
2. **Gate 01 — Clientes + Estoque (ledger):** cadastro de cliente (PF/PJ, tipo, CPF/CNPJ) + `inventory_movements` multi-estado + painel de saldos. Migração dos saldos iniciais como ajuste de inventário no estado correto (`ACABADO` vs `CRU_SECO`).
3. **Gate 02 — Compras/Recebimento:** entrada de peça crua + quarentena/secagem + custo + conta a pagar.
4. **Gate 03 — Produção:** OP, consumo de insumos, quebra, custeio ABC, kanban.
5. **Gate 04 — Vendas:** pedido com status, reserva, prazo realista, preço por lista, PDF/WhatsApp.
6. **Gate 05 — Financeiro:** a receber/pagar, caixa, DRE simples, baixa manual/PIX.
7. **Gate 06 — Sync Woo bidirecional** (estoque `ACABADO`) **+ mídia canônica** (ADR-0017 fase 2).
8. **Gate 07 — Fiscal (sob demanda):** NF-e via ACBr/PyNFe conforme enquadramento.

---

## 7. Perguntas a validar com o dono do negócio

1. **Secagem:** tempo é fixo por tipo/tamanho (default de `drying_days`) ou "por sensação"? Define liberação automática por data vs manual.
2. **Enquadramento fiscal:** MEI ou ME/Simples? Define se/quando o Gate 07 (NF-e automatizada) é necessário.
3. **Custeio:** entra mão de obra por minuto de bancada já no Gate 03, ou começa com custo de peça + insumos e evolui?
4. **Boleto/PIX:** o banco atual oferece API de cobrança PIX gratuita para PJ? (Destrava o M-01 sem custo.)
5. **Segredos expostos:** já foram rotacionados após o commit `e13ae02`? (Se não, é a prioridade zero.)

---

### Anexo — Pontos fortes a preservar
- Modelagem que separa **produto (SKU/preço/ficha)** de **saldo (ledger)** — evita a segunda fonte de verdade.
- **Dinheiro em `DECIMAL` + brick/money**, quantidades em `DECIMAL(15,3)`.
- **Invariantes no modelo** (SKU imutável, preço append-only) — valem para qualquer caminho de escrita.
- **Cultura docs-first + ADRs + BR-xxx** — mantê-la, mas com o cuidado de não deixar os docs de estado (CLAUDE.md/.planning) atrasarem em relação ao código.
- **Integração como canal** (Woo nunca é o core) — decisão que evita caos de dados.

### Regras de ouro para a continuação
1. Estoque só muda por **movimento imutável** no ledger — inclusive secagem, quebra e reserva.
2. **Dinheiro nunca em float**; **segredo nunca no repo**; **PII nunca versionada**.
3. Todo estado de produção é um estado de estoque — modele os dois juntos, não em paralelo.
4. Prazo de venda considera **secagem + pintura**; nunca prometa saldo `ACABADO` que não existe.
5. Docs de estado acompanham o código na mesma PR — sem drift.
