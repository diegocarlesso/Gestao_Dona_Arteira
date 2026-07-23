# Checkpoint — 2026-07-23

> Ponto de retomada do trabalho. Não é documento canônico de arquitetura — é um "onde paramos e o que fazer a seguir". Substitui o checkpoint de 2026-07-22. Apagar quando o Gate 01 tiver seu próprio acompanhamento.

## Onde chegamos hoje

Saímos de "aplicação no ar sem código de negócio" para **o primeiro módulo do ERP construído e verificado**, com o pipeline de CI efetivamente rodando pela primeira vez.

### O CI nunca tinha rodado

Os dois workflows estavam em `gestao-app/.github/workflows/`. O GitHub Actions só lê `.github/workflows/` **na raiz do repositório** — o pipeline descrito como "bloqueante" jamais executou. Movidos para a raiz com `defaults.run.working-directory: gestao-app`.

Com ele efetivamente rodando, todos os gates reprovaram — sempre em código do starter kit, nunca em código nosso. Corrigidos: `strict_types` em 16 arquivos, dois erros reais de tipo (Larastan nível 6), 10 arquivos fora do padrão do Pint, 4 erros de TypeScript e a ausência total de testes de frontend.

### Estado dos gates (todos verdes, verificados localmente)

| Gate | Estado |
|---|---|
| Pest | ✅ 98 testes, 403 asserções |
| Vitest | ✅ 4 testes |
| PHPStan (Larastan nível 6) | ✅ sem erros |
| Pint · ESLint · Prettier · tsc | ✅ |
| `npm run build` | ✅ |
| migrate → rollback → migrate | ✅ (agora é passo do CI, regra 2 da pasta 04) |
| `db:seed` duas vezes seguidas | ✅ sem duplicar nada |

### Módulo Identity — construído

Primeira ocupação de `app/Modules/` conforme ADR-0020; o teste `arch()` já gerou a regra do módulo sozinho.

| Peça | Arquivo |
|---|---|
| Permissões (24, formato `modulo.acao`) | `app/Modules/Identity/Enums/Permission.php` |
| Papéis (6) + matriz da pasta 19 | `app/Modules/Identity/Enums/Role.php` |
| Ciclo de vida da conta | `app/Modules/Identity/Enums/UserStatus.php` |
| Model auditável com RBAC | `app/Modules/Identity/Models/User.php` |
| `Gate::before` do admin | `app/Modules/Identity/Providers/IdentityServiceProvider.php` |
| Seeder de papéis (idempotente e convergente) | `database/seeders/Identity/RolePermissionSeeder.php` |
| Admin inicial | `database/seeders/Identity/AdminInicialSeeder.php` |
| Middlewares de conta | `app/Modules/Identity/Http/Middleware/` — `conta.ativa`, `senha.trocada` |
| Policy, Services, Eventos | `app/Modules/Identity/{Policies,Services,Events}/` |
| Telas | `resources/js/pages/identity/` — listagem, criação, edição, troca obrigatória |
| Gating de UI | `resources/js/lib/permissions.ts` (`usePermissions().can(...)`) |
| Testes | `tests/Feature/Identity/` (7 arquivos, 66 testes) |

### Ambiente local

- MariaDB do XAMPP passa a ser o banco de **desenvolvimento** também (`erp_dev`), não só de teste (`erp_test`). O `.env` apontava para SQLite enquanto testes e produção rodam em MariaDB — a divergência que o ADR-0002 existe para impedir.
- ⚠️ O `mysqld` do XAMPP não está registrado como serviço e **caiu uma vez** durante a sessão. Ao retomar, conferir com `Get-Process mysqld` e, se preciso, subir com `Start-Process C:\xampp\mysql\bin\mysqld.exe -ArgumentList "--defaults-file=C:\xampp\mysql\bin\my.ini","--standalone"`.

## Publicado em produção (2026-07-23)

O módulo Identity está no ar. Login verificado de ponta a ponta:
`POST /login` → 302 → `/dashboard` 200 com sessão do admin.

**O deploy revelou que a produção não estava como a documentação
afirmava.** Dois estados errados, ambos invisíveis porque a aplicação
respondia normalmente:

| Achado | Realidade | Corrigido |
|---|---|---|
| `DB_CONNECTION=sqlite` | A aplicação nunca esteve em MariaDB. Com `DB_DATABASE=u917402451_da_erp` e driver sqlite, o Laravel tratou o nome do banco como **caminho de arquivo** e criou um SQLite chamado `u917402451_da_erp` na raiz da app. O banco `u917402451_da_erp` do MariaDB estava **vazio** | ✅ `DB_CONNECTION=mysql`; as 6 migrations rodaram no MariaDB |
| `APP_DEBUG=true` | Qualquer erro renderizaria stack trace com o conteúdo do `.env` | ✅ `false`, verificado por requisição a rota inexistente |
| 3 chaves duplicadas no `.env` | `SESSION_DRIVER`, `QUEUE_CONNECTION`, `CACHE_STORE` apareciam duas vezes | ✅ deduplicadas mantendo a última |
| `proc_open` desabilitada de novo | Armadilha **P-15**. Quebrou o `package:discover` do composer | ✅ contornado rodando `php artisan package:discover` direto (não usa Process) |

O checkpoint anterior afirmava "banco produção ✅ migração base rodada" —
estava errado. `migrate:status` dizia "Ran" sem dizer *em qual banco*; a
pergunta que revela isso é `config("database.default")`.

**Estado do banco de produção:** 6 migrations, 6 papéis, 24 permissões,
1 usuário admin. Backups em `~/backups/` (`.env`, SQLite antigo, dump).

### 🔴 Pendências abertas pelo deploy

1. ✅ **Senha do banco rotacionada** pelo dono em 2026-07-23. Ela havia
   aparecido na saída de um `grep` durante a inspeção — pela
   [pasta 25](../25-Seguranca/README.md), senha exposta se rotaciona.
   Conexão conferida depois da troca.
2. ✅ **Senha do admin trocada** pelo dono. O middleware que *obriga* a
   troca passou a existir na mesma sessão (§ abaixo), mas **ainda não
   está publicado** — vale a partir do próximo deploy.
3. **`proc_open` volta a ser desabilitada** pelo painel. Reabilitar em
   hPanel → PHP → `disable_functions`, ou aceitar o contorno documentado
   no runbook 04 §7.
4. 🔴 **P-16 — a extensão `psr` quebra todo o log da aplicação.**
   ⚠️ **A tentativa de correção foi na direção contrária:** a caixa foi
   **marcada** no painel às 13:45, e `extension=psr.so` continua no
   `alt_php.ini` — `extension_loaded('psr')` segue `true`. O lado útil é
   que ficou provado que o painel controla esse arquivo (ele foi
   reescrito no horário da ação); basta **desmarcar**.
   É o
   achado mais grave da sessão. O servidor carrega `extension=psr.so` em
   ambos os SAPIs; ela declara `Psr\Log\LoggerInterface` com a assinatura
   antiga do PSR-3, incompatível com o Monolog 3 do Laravel 12. Qualquer
   requisição que precise logar morre com **500 em branco, sem registrar
   nada** — o mecanismo que existe para explicar falhas é o próprio que
   falha. Comprovado por dois arquivos idênticos em `public/` diferindo
   só pela instanciação do `Monolog\Logger`: 200 contra 500.
   **Correção: hPanel → PHP Configuration → Extensions → desmarcar
   `psr`.** Não há alternativa por código — todos os canais de log do
   Laravel passam por Monolog. Detalhe em
   [04 §7](04-atualizar-producao.md).

5. ✅ **Agendador: resolvido, era falso alarme.** O cron **executa** — dois
   batimentos consecutivos, um por minuto. O `~/schedule.log` nunca
   existiu porque **o hPanel não honra o redirecionamento escrito no
   campo de comando do cron**; a saída só vai para o "View Output" do
   painel. Isso responde em definitivo a dúvida que o checkpoint de 22/07
   deixara em aberto. O `scheduler.sh` passou a gravar
   `~/scheduler-ultima-execucao.txt` por conta própria — sobrescrevendo,
   não acumulando.

### Dívida registrada: `npm audit`

`npm audit` acusa 13 vulnerabilidades (2 críticas) — **nenhuma das
críticas chega ao navegador**, verificado e não presumido:

| Pacote | Severidade | Chega ao bundle? |
|---|---|---|
| `form-data`, `follow-redirects` | crítica / moderada | ❌ Adaptador Node do axios, removido pelo tree-shaking. Os internos do pacote (`CombinedStream`, `_boundary`, `getLengthSync`) têm **zero ocorrências** no bundle |
| `shell-quote`, `lodash` | crítica / alta | ❌ Vêm de `concurrently`, usado só pelo script `composer dev` |
| `axios` 1.7.9 | **alta** | ✅ **sim** — SSRF por URL absoluta e DoS por falta de checagem de tamanho |

O `axios` é o único que embarca. O caminho explorável é estreito porque o
Inertia só chama rotas da própria aplicação e nunca recebe URL vinda do
usuário — mas é dívida real.

Corrigir exige escolha: `npm audit fix` mexe em **59 pacotes** (ciclo
próprio de verificação, não carona num release), ou subir
`@inertiajs/react` de 2.0.3 para 3.6.1, que é *major* e precisa de
trabalho dedicado.

## Telas de gestão de contas — construídas, **ainda não publicadas**

Segunda entrega do Identity, depois do deploy. Tudo em `main`, verde no
CI, mas **a produção ainda roda a versão anterior**.

| O quê | Onde |
|---|---|
| Listagem com busca (filtro na URL) | `resources/js/pages/identity/users/index.tsx` |
| Criação de conta | `.../users/create.tsx` |
| Edição: papéis + ciclo de vida | `.../users/edit.tsx` |
| Troca obrigatória de senha | `resources/js/pages/identity/forced-password.tsx` |
| Middlewares `conta.ativa` e `senha.trocada` | `app/Modules/Identity/Http/Middleware/` |
| Policy, Services, Eventos | `app/Modules/Identity/{Policies,Services,Events}/` |

**Três decisões que a implementação forçou:**

1. **`AuthorizesRequests` no `Controller` base.** O Laravel 12 entrega a
   classe vazia; sem o trait, `$this->authorize()` é método inexistente.
   A falta só apareceu quando o primeiro controller de verdade foi
   escrito.
2. **`changeStatus` e `assignRoles` fora do atalho do admin.** O
   `Gate::before` roda antes das Policies e curto-circuita a decisão —
   atropelaria a proteção que impede alguém de se promover ou de suspender
   a própria conta.
3. **Props compartilhadas passam campos escolhidos um a um**, não o model
   inteiro: o que sai dali viaja em toda visita e aparece no HTML de cada
   página.

## PRÓXIMO PASSO (retomar exatamente aqui)

**1. 🔴 Desmarcar `psr` no hPanel** (pendência 4 acima) — a tentativa
anterior marcou em vez de desmarcar. Conferir com
`ssh gestao-prod "php -r 'var_dump(extension_loaded(\"psr\"));'"`, que
precisa dar `bool(false)`, e validar também pelo SAPI web.

**2. Publicar em produção** pelo [runbook 04](04-atualizar-producao.md).
É o que leva as telas de gestão e os dois middlewares para o ar. Sem
isso, o `must_change_password` continua sendo coluna sem efeito lá.

**3. Decidir as 6 células 🆕 restantes da matriz de permissões**
([pasta 19 §3.1](../19-Permissoes/README.md)). Todas ampliam acesso. A de
`sales.view` → `finance` já foi confirmada pelo dono em 2026-07-23.

**4. Fechar o que o Identity ainda deve à documentação:**
   - Canal `security_events` (pasta 26 §2) — login ok/falha, `PermissionDenied`, mudança de papel. Os eventos já existem; falta quem os ouça e a tabela.
   - Fluxo de 2FA TOTP (BR-804): as colunas existem, o fluxo não. A listagem já exibe a pendência por conta.
   - Convite por e-mail (pasta 18 §3): o estado `invited` e o evento `UserInvited` existem; falta o listener que envia.
   - `last_login_at` nunca é preenchido — falta o listener do evento `Login`.

**5. Depois do Identity: módulo Catalog** — produtos, SKU (BR-002),
preços varejo/atacado (BR-003), embalagens (BR-004). É o que o Estoque e
as Vendas precisam existir antes.

## Decisões pendentes que não bloqueiam o código

- **ADR-0018** (cobrança boleto/PIX) — ainda `Proposto`, aguarda o dono e a resposta do cliente sobre o banco.
- ✅ **`Dona_Arteira_Gestao_desktop/`** — desrastreado em 2026-07-23 por decisão do dono; segue no disco e no histórico do git.
- **Externas de lead longo:** pauta fiscal ao contador (`docs/13-Fiscal/01`), convênio bancário, chaves REST do Woo, dump do banco `dona_arteira` do desktop.
- **M-5** (backup automatizado), **M-8** (certificado A1 fora do webroot), **CA bundle** (`curl.cainfo`, antes do Gate 05).
- **Dívida do `npm audit`** (acima) — merece ciclo próprio, não carona num release.

## Armadilhas deste ambiente (já documentadas, para não repetir)

P-13 cron usa caminho absoluto · P-14 interpretador explícito + arquivo 644, nunca 777 · P-15 trocar versão de PHP troca `disable_functions` (revalidar sempre) · **P-16 extensão `psr` quebra o Monolog — todo log fatal** · symlink: `ln -s` do shell funciona, `symlink()` do PHP não · hPanel gerencia cron por fora (`crontab` nem existe no host) · **hPanel não honra o redirecionamento escrito no comando do cron** · comando longo no painel quebra (usar wrapper) · PHPStan estoura os 128 M do PHP CLI — usar `composer analyse`.
