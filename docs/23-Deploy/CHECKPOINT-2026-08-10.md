# Checkpoint — 2026-08-10

> Ponto de retomada do trabalho. Não é documento canônico de arquitetura — é um "onde paramos e o que fazer a seguir". Substitui o checkpoint de 2026-07-28. Apagar quando o P0 (Compras/Fiscal) tiver seu próprio acompanhamento fechado.

## Onde chegamos

Sessão longa: a diretoria descartou o rumo multi-cliente/white-label e redefiniu a prioridade (P0) como **Compras → NF-e → gatilho automático WooCommerce→NF-e**, seguido de P1 (clientes unificados). Tudo abaixo está **em produção**.

`main` = produção = `6f92b14`. Suíte **615 testes Pest**, PHPStan nível 6, Pint, tsc, build — tudo verde.

```
Compras:  fornecedor + PC + itens → confirmar gera conta a pagar (Finance mínimo)
Fiscal:   pedido confirmado do site → job de NF-e → Invoice pending (NullNfeGateway)
          gate BR-309: pedido do site não expede sem NF-e autorizada
          gateway real (sped-nfe) escrito e testado contra XSD, ainda não ligado
Vendas:   endereço resolve código IBGE do município sozinho (ADR-0026)
Migração: importador de clientes Woo pronto (extração→triagem→carga→validação),
          dry-run já confirma 198 cadastros — carga real ainda não rodou
```

---

## 1. P0.1 — Compras (`Purchasing` + `Finance`)

Fornecedor (CNPJ/CPF validado), Pedido de Compra com itens, máquina de estados `rascunho → enviado → confirmado (+ cancelado)`. Confirmar chama `Finance\Services\RegisterPayableService` na mesma transação e gera o título — sem tocar Estoque (decisão da diretoria: fase 1 é registro financeiro/documental; recebimento físico e quarentena de secagem ficam para quando o Estoque entrar, P2).

Telas em `/fornecedores` e `/compras`, permissão `purchasing.manage`.

## 2. P0.2/P0.3 — Fiscal (NF-e) + gatilho automático

[ADR-0025](../27-ADR/ADR-0025-emissao-automatica-nfe.md): módulo `Fiscal` único (perfis + emissão), gatilho via `Sales\Events\OrderConfirmed` (só canal `woocommerce` neste corte), gate BR-309 em `FulfillmentService::expedir()`.

**Pipeline técnico completo, emissão real ainda inerte.** `NullNfeGateway` é o bind ativo — sempre devolve `pending`, nunca autoriza. `NFE_ENABLED=false` em produção. Faltam, nenhuma delas código:
1. **Certificado A1** (real ou de homologação).
2. **Dados do contador**: CNPJ/razão social/IE/regime da empresa + CST de PIS/COFINS (`NFE_EMITENTE_*`, `NFE_PIS_CST`, `NFE_COFINS_CST`) — bloqueado em `docs/13-Fiscal` até a pauta de validação.

O gateway real (`SpedNfeGateway`) já existe e foi **verificado contra o XSD oficial da NF-e 4.00** com certificado autoassinado nos testes (chave de acesso, CFOP/CSOSN, totais ao centavo, regra de homologação BR-605) — só não foi testado contra a SEFAZ de verdade.

## 3. Código IBGE do município ([ADR-0026](../27-ADR/ADR-0026-codigo-ibge-municipio.md), BR-607)

Lacuna descoberta pelo próprio `SpedNfeGateway` (recusava emitir sem `enderDest/cMun`) e fechada no mesmo dia. Tabela `ibge_municipalities` com os **5.571 municípios oficiais** (fonte: API pública do IBGE, baixada uma vez, versionada — sem dependência externa em runtime). `customer_addresses.city_code` resolve sozinho ao salvar (UF + nome normalizado); sem casamento exato, fica pendência visível — nunca aproximação. Fallback na tela é **escolher** o município, não digitar código (há FK).

Achado que valeu um teste próprio: **há duas Jacutingas** (MG e RS) — a do RS é a cidade da própria loja.

**Endereços já cadastrados ainda não foram resolvidos** — `php artisan erp:enderecos:resolver-ibge` existe (simula por padrão, `--gravar` aplica) e não rodou ainda.

## 4. Importador de clientes WooCommerce (P1)

Mesmo padrão do importador de catálogo (docs/17-Migração, ADR-0010): extração→triagem→carga→validação, comandos `erp:migrate:{extract,triage,load,validate} clientes`.

**Escopo decidido:** só os clientes com pedido real — dos 198 cadastros do Woo, o inventário mediu 62 compradores. Os ~136 sem compra ficam de fora por minimização de dados pessoais (LGPD). A carga trata os três casos que a integração de pedidos já em produção obriga: cliente já auto-criado por um pedido importado (enriquece, não duplica), cliente já cadastrado manualmente no ERP casando por e-mail/doc (mescla), nenhum dos dois (cria novo).

**Extração em dry-run já rodou contra o Woo real em 2026-08-10: 198 cadastros encontrados — bate exatamente com o inventário.** Nada foi gravado. A extração/triagem/carga de verdade ainda não rodou.

## 5. Achados de produção durante o deploy (vale saber para a próxima sessão)

- **Trabalho paralelo concorrente no GitHub**: outra sessão fez merge direto em `origin/main` (e-mail transacional BR-310, correções de Dashboard, e um `ADR-0017-modelo-distribuicao-white-label.md` que contradizia a decisão desta sessão de descartar o rumo white-label). O dono confirmou que o rumo branding/white-label já tinha sido descartado antes; o ADR foi **removido** do repositório numa sessão seguinte. Se outra sessão Claude Code estiver rodando em paralelo de novo, `git fetch`/`git log HEAD..origin/main` antes de qualquer deploy.
- **`.env` local usa o mesmo nome de banco/usuário de produção** (`u917402451_da_erp`/`u917402451_erp`), mas `DB_HOST=127.0.0.1` — não há risco ao servidor remoto, mas um `migrate:fresh` local descuidado apagaria qualquer cópia local com esse nome. Vale renomear o banco de dev para algo distinto (não feito ainda).
- **Suítes de teste concorrentes destroem `erp_test`**: dois agentes rodando `pest` completo ao mesmo tempo no mesmo schema geram `SQLSTATE[40001] Deadlock` em massa (não é regressão real). Isolar com `DB_DATABASE=schema_privado` quando houver dúvida.
- Sequência de commits desta sessão ficou dividida por módulo (Finance → Fiscal → Purchasing → fix do provider esquecido → flag de reserva opcional de Vendas → ADR-0025/pré-flight → gateway sped-nfe → IBGE → importador de clientes) — útil para quem for revisar o histórico.

## 6. Por onde continuar

### Imediato
- **Carga real dos 62 clientes**: `erp:migrate:triage clientes` → `erp:migrate:load clientes --dry-run` (revisar o relatório de merges antes de aplicar) → `erp:migrate:load clientes` → `erp:migrate:validate clientes`.
- **Backfill do código IBGE** nos endereços que a carga acima trouxer (e nos que já existiam): `erp:enderecos:resolver-ibge`, revisar o relatório, depois `--gravar`.

### Pendente (task registrada, não iniciada)
- **Exclusão de contagem de estoque aberta e de peças na aba Estoque** — pedido do dono, ainda não investigado. Respeitar ADR-0008 (ledger imutável) e BR-008 (sem exclusão física com movimento).

### Bloqueado por terceiro
- Pauta de validação fiscal com o contador (`docs/13-Fiscal/01`) — destrava dados do emitente e CST de PIS/COFINS.
- Certificado A1 (real ou homologação) — destrava ligar `SpedNfeGateway` de fato.

### Depois: P2 (Estoque/Produção completos)
Não expandir além do ADR-0008 até P0/P1 fecharem operacionalmente.
