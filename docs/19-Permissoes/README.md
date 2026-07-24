# 19 — Permissões (RBAC)

> **Status:** ✅ **Implementado e em produção** — matriz, seeder e telas · **Última atualização:** 2026-07-24 · **Responsável:** security-specialist
> **Regras:** BR-801…BR-804 · **Fase:** Gate 01 · **ADR:** [0011 (spatie/laravel-permission)](../27-ADR/ADR-0011-rbac.md)
> **Código:** `app/Modules/Identity/Enums/{Role,Permission}.php` · `database/seeders/Identity/RolePermissionSeeder.php` · `tests/Feature/Identity/`

## 1. Objetivo

Controle de acesso **negado por padrão** (BR-801): papéis agrupam permissões granulares; toda rota da API exige permissão explícita; a UI esconde o que o usuário não pode (autoridade sempre no backend).

## 2. Modelo

- Permissões nomeadas `modulo.acao`: `catalog.view`, `catalog.manage`, `inventory.view`, `inventory.move`, `inventory.adjust`, `production.view`, `production.execute`, `production.manage`, `sales.view`, `sales.create`, `sales.cancel`, `sales.discount.approve`, `fulfillment.execute`, `purchasing.manage`, `finance.view`, `finance.settle`, `finance.manage`, `fiscal.view`, `fiscal.emit`, `fiscal.cancel`, `reports.view`, `integrations.manage`, `users.manage`, `audit.view`.
- Papéis são conjuntos versionados por seeder (mudança de papel = migration de seed + registro em auditoria). Usuário pode acumular papéis.
- Policies do Laravel implementam nuances contextuais (ex.: `sales.cancel` só do próprio pedido para `sales`, qualquer um para `admin`).

## 3. Matriz papel × permissão (inicial)

> **Esta tabela é a fonte da verdade.** Ela é traduzida literalmente para
> `App\Modules\Identity\Enums\Role::permissions()` e verificada célula a
> célula pelo teste `tests/Feature/Identity/MatrizPapelPermissaoTest.php`.
> Mudar um `✅` aqui sem mudar o enum (ou vice-versa) **reprova o CI** —
> é assim que documento e código não divergem.
>
> Uma linha por permissão, sem abreviação. A versão anterior agrupava
> permissões (`inventory.move / adjust`) e omitia `production.view`,
> `sales.view` e as células de `fiscal.view`, o que deixava o
> comportamento em aberto justamente onde BR-801 exige que não esteja.
>
> **Não há mais célula pendente.** As seis que foram preenchidas por
> inferência ao destravar a matriz abreviada foram confirmadas pelo dono
> em 2026-07-23 e 2026-07-24 — o registro de cada uma está na §3.1.

| Permissão | admin | production | sales | fulfillment | finance | accountant |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `catalog.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `catalog.manage` | ✅ | — | — | — | — | — |
| `inventory.view` | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| `inventory.move` | ✅ | ✅ | — | ✅ | — | — |
| `inventory.adjust` | ✅ | — | — | — | — | — |
| `production.view` | ✅ | ✅ | ✅ | ✅ | — | — |
| `production.execute` | ✅ | ✅ | — | — | — | — |
| `production.manage` | ✅ | — | — | — | — | — |
| `sales.view` | ✅ | — | ✅ | ✅ | ✅ | — |
| `sales.create` | ✅ | — | ✅ | — | — | — |
| `sales.cancel` | ✅ | — | ✅ \* | — | — | — |
| `sales.discount.approve` | ✅ | — | — | — | — | — |
| `fulfillment.execute` | ✅ | — | ✅ | ✅ | — | — |
| `purchasing.manage` | ✅ | — | — | — | — | — |
| `finance.view` | ✅ | — | — | — | ✅ | — |
| `finance.settle` | ✅ | — | — | — | ✅ | — |
| `finance.manage` | ✅ | — | — | — | ✅ | — |
| `fiscal.view` | ✅ | — | ✅ | ✅ | ✅ | ✅ |
| `fiscal.emit` | ✅ | — | ✅ | ✅ | — | — |
| `fiscal.cancel` | ✅ | — | — | — | — | — |
| `reports.view` | ✅ | — | — | — | ✅ | ✅ \*\* |
| `integrations.manage` | ✅ | — | — | — | — | — |
| `users.manage` | ✅ | — | — | — | — | — |
| `audit.view` | ✅ | — | — | — | — | — |

\* **Restrição de Policy**, não de permissão: `sales` só cancela os
próprios pedidos e apenas antes da expedição; `admin` cancela qualquer
um. A permissão abre a porta; a Policy decide o caso.

\*\* **Restrição de Policy:** `accountant` vê apenas relatórios fiscais
(BR-803). A permissão `reports.view` é a mesma; o filtro é contextual.

### 3.1 As seis células que ampliaram acesso — registro da decisão

A matriz abreviada anterior omitia seis células. Elas foram preenchidas
por inferência, marcadas com 🆕 e submetidas ao dono, porque **todas
ampliam acesso** e BR-801 manda negar por padrão. Todas foram
**confirmadas**; nenhuma foi recusada.

Este registro fica porque a pergunta "por que este papel vê isto?"
reaparece na revisão trimestral de acessos (pasta 25) — e a resposta
"sempre foi assim" é a que faz a permissão inflar.

| Célula | Confirmada em | Por quê | O que aceitamos junto |
|---|---|---|---|
| `sales.view` → finance | 2026-07-23 | Título a receber nasce de um pedido; conferir a origem é rotina do financeiro | **Expõe dados de cliente ao financeiro.** Aceito por ser rotina do papel. Revisitar se a pasta 25 endurecer a minimização de dados |
| `production.view` → sales | 2026-07-24 | Encomenda tem prazo, e a secagem impõe dias de espera; vendas responde "quando fica pronto" sem interromper a produção | Baixo — a OP não expõe custo de MP nem dado pessoal. Custo e ficha técnica são `production.manage` |
| `production.view` → fulfillment | 2026-07-24 | Expedição planeja a fila de separação pelo que vai ficar pronto, em vez de reagir ao que apareceu no estoque | Baixo — idem. Foi decisão consciente: para o trabalho do dia o `inventory.view` que o papel já tem bastaria, e a célula existe para o **planejamento** |
| `sales.view` → fulfillment | 2026-07-24 | Não dá para separar e embalar um pedido sem ler o pedido | É pré-requisito prático do `fulfillment.execute`, que o papel já tem — sem ele a permissão existente não seria utilizável |
| `fiscal.view` → sales | 2026-07-24 | A matriz dava `fiscal.emit` sem `fiscal.view`. Emitir sem poder reconsultar a nota emitida é incoerente | Baixo — quem emite já vê o documento no ato da emissão |
| `fiscal.view` → fulfillment | 2026-07-24 | Idem: o papel tem `fiscal.emit` | Baixo — idem |

**Papéis se acumulam** (pasta 18 §2), e isso reduz o custo de errar para o
lado restritivo: a mesma pessoa como `sales` e `fulfillment` recebe a
união das permissões. Negar uma célula não deixa ninguém sem informação
se o outro papel a concede — o argumento vale ao revisitar qualquer linha
desta tabela.

Para mudar qualquer célula é preciso editar **três** lugares coerentes —
esta tabela, o enum `Role` e o teste da matriz. Ver §5.1 para o porquê da
chatice.

## 4. Alçadas (regras quantitativas)

| Ação | Limite sem aprovação | Acima do limite |
|---|---|---|
| Desconto em pedido (BR-305) | até X% (definir com o dono — pergunta aberta) | exige `sales.discount.approve` |
| Ajuste de inventário (BR-205) | nunca sozinho | aprovador ≠ contador da divergência |
| Cancelamento de NF-e | — | somente `fiscal.cancel` (admin), com motivo |

## 5. Aplicação técnica

Rota → middleware de permissão → Policy (contexto) → Service. Testes de autorização são **obrigatórios por endpoint** (pasta 22): papel sem permissão recebe 403 (testado explicitamente). Auditoria registra `PermissionDenied` (pasta 26).

### 5.1 Onde cada peça vive (implementado em 2026-07-23)

| Peça | Arquivo | Papel |
|---|---|---|
| Permissões | `app/Modules/Identity/Enums/Permission.php` | Enum de 24 casos, formato `modulo.acao` |
| Papéis + matriz | `app/Modules/Identity/Enums/Role.php` | `permissions()` transcreve a coluna do papel na §3 |
| Materialização | `database/seeders/Identity/RolePermissionSeeder.php` | Idempotente **e convergente** — `syncPermissions` revoga o que saiu do enum, e permissão órfã é apagada |
| Admin implícito | `app/Modules/Identity/Providers/IdentityServiceProvider.php` | `Gate::before` devolve `true` para `admin` e `null` (não `false`) para os demais, para não atropelar as Policies |
| Policy de contas | `app/Modules/Identity/Policies/UserPolicy.php` | `users.manage` + as nuances que a permissão sozinha não expressa |
| Gating de UI | `resources/js/lib/permissions.ts` | `usePermissions().can('users.manage')` — as permissões chegam nas props compartilhadas |
| Telas | `resources/js/pages/identity/` | Listagem com busca, criação, edição (papéis + ciclo de vida), troca obrigatória de senha |
| Verificação | `tests/Feature/Identity/MatrizPapelPermissaoTest.php` | Compara célula a célula contra uma cópia independente da matriz |

**Duas habilidades ficam fora do atalho do admin**, por
`SEMPRE_PELA_POLICY` no provider: `changeStatus` e `assignRoles`. O
`Gate::before` roda antes das Policies e curto-circuita a decisão — sem
essa exclusão, um admin conseguiria se promover ou suspender a própria
conta, porque a Policy nem seria consultada. Há teste para os dois lados.

**Por que o teste repete a matriz em vez de ler o enum:** um teste que
lesse `Role::permissions()` provaria apenas que o enum é igual a si
mesmo. Ampliar acesso passa a exigir três edições coerentes — documento,
enum e teste. É chato de propósito: BR-801 diz que acesso é negado por
padrão, e conceder demais por descuido é o erro que essa chatice previne.

**Admin não tem lista literal.** `Role::Admin` devolve `Permission::cases()`.
Uma permissão nova nasceria fora do alcance de quem administra o sistema,
e a falta só apareceria em produção.

## 6. Riscos

| Risco | Mitigação |
|---|---|
| Permissões inflarem caso a caso até todo mundo ser admin | Revisão trimestral de acessos (checklist pasta 25); mudanças de papel auditadas |
| Papel `accountant` receber dados de clientes além do fiscal | Resources específicos por papel testados |

## 7. Evoluções futuras

- Permissões por local/loja (se multi-local operacional).
- Aprovações em duas etapas com fila de pendências no dashboard (fase 6).
