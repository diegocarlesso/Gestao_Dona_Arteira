# 19 — Permissões (RBAC)

> **Status:** ✅ **Implementado** (matriz e seeder; telas pendentes) · **Última atualização:** 2026-07-23 · **Responsável:** security-specialist
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

| Permissão | admin | production | sales | fulfillment | finance | accountant |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `catalog.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `catalog.manage` | ✅ | — | — | — | — | — |
| `inventory.view` | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| `inventory.move` | ✅ | ✅ | — | ✅ | — | — |
| `inventory.adjust` | ✅ | — | — | — | — | — |
| `production.view` | ✅ | ✅ | 🆕 ✅ | 🆕 ✅ | — | — |
| `production.execute` | ✅ | ✅ | — | — | — | — |
| `production.manage` | ✅ | — | — | — | — | — |
| `sales.view` | ✅ | — | ✅ | 🆕 ✅ | 🆕 ✅ | — |
| `sales.create` | ✅ | — | ✅ | — | — | — |
| `sales.cancel` | ✅ | — | ✅ \* | — | — | — |
| `sales.discount.approve` | ✅ | — | — | — | — | — |
| `fulfillment.execute` | ✅ | — | ✅ | ✅ | — | — |
| `purchasing.manage` | ✅ | — | — | — | — | — |
| `finance.view` | ✅ | — | — | — | ✅ | — |
| `finance.settle` | ✅ | — | — | — | ✅ | — |
| `finance.manage` | ✅ | — | — | — | ✅ | — |
| `fiscal.view` | ✅ | — | 🆕 ✅ | 🆕 ✅ | ✅ | ✅ |
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

### 3.1 Células marcadas com 🆕 — inferidas, aguardando confirmação

Sete células não existiam na matriz abreviada e foram preenchidas com a
leitura mínima que mantém a operação coerente. Todas **ampliam** acesso,
então merecem confirmação do dono antes do Gate 02:

| Célula | Raciocínio | Risco se estiver errado |
|---|---|---|
| `production.view` → sales | Encomenda tem prazo; vendas precisa responder "quando fica pronto" sem pedir para outra pessoa | Baixo — leitura de OP não expõe custo nem dado pessoal |
| `production.view` → fulfillment | Expedição planeja a separação pelo que vai ficar pronto | Baixo — idem |
| `sales.view` → fulfillment | Não dá para separar e embalar um pedido sem ler o pedido | Baixo — é pré-requisito de `fulfillment.execute`, que o papel já tem |
| `sales.view` → finance | Título a receber nasce de um pedido; conferir a origem é rotina do financeiro | Médio — expõe dados de cliente ao financeiro (avaliar sob LGPD, [pasta 25](../25-Seguranca/README.md)) |
| `fiscal.view` → sales | A matriz dava `fiscal.emit` a `sales` sem `fiscal.view`. Emitir sem poder ler a nota emitida é incoerente | Baixo — quem emite já vê o documento no ato |
| `fiscal.view` → fulfillment | Idem: o papel tem `fiscal.emit` | Baixo — idem |

Se alguma for recusada, remover a célula aqui **e** no enum `Role` — o
teste da matriz aponta a divergência imediatamente.

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
| Verificação | `tests/Feature/Identity/MatrizPapelPermissaoTest.php` | Compara célula a célula contra uma cópia independente da matriz |

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
