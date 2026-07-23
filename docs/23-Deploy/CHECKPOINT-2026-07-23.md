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
| Pest | ✅ 62 testes, 255 asserções |
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
| Testes | `tests/Feature/Identity/` (5 arquivos, 30 testes) |

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

1. **Rotacionar a senha do banco de produção.** Ela apareceu na saída de
   um `grep` durante a inspeção e está no transcrito da sessão. Pela
   [pasta 25](../25-Seguranca/README.md), senha exposta se rotaciona.
2. **Trocar a senha do admin.** A conta nasceu com `must_change_password`,
   mas **o middleware que obriga a troca ainda não existe** — a flag está
   no banco e ninguém a consulta. Trocar manualmente pelo perfil.
3. **`proc_open` volta a ser desabilitada** pelo painel. Reabilitar em
   hPanel → PHP → `disable_functions`, ou aceitar o contorno documentado
   no runbook 04 §7.
4. **Agendador:** `~/schedule.log` não existe, apesar de o job constar no
   hPanel e o `scheduler.sh` funcionar quando chamado à mão. Confirmar se
   o cron está de fato executando — a fila depende dele (ADR-0014).

## PRÓXIMO PASSO (retomar exatamente aqui)

**1. Fechar as 4 pendências acima**, na ordem em que estão.

**2. Decidir as 6 células 🆕 restantes da matriz de permissões** ([pasta 19 §3.1](../19-Permissoes/README.md)). Todas ampliam acesso. A de `sales.view` → `finance` já foi confirmada pelo dono em 2026-07-23.

**3. Telas do Identity (Inertia)**: gestão de usuários, atribuição de papéis, tela de troca obrigatória de senha no primeiro acesso. Hoje o módulo tem domínio e dados, mas nenhuma tela — o admin inicial entra pela tela de login do starter kit.

**4. Fechar o que o Identity ainda deve à documentação** — os dois primeiros são pendência aberta em produção agora:
   - 🔴 Middleware que barra login de conta não-`Active` (o model já sabe responder; ninguém pergunta ainda).
   - 🔴 Middleware de `must_change_password` — a flag do admin de produção está ligada e não surte efeito.
   - Canal `security_events` (pasta 26 §2) — login ok/falha, `PermissionDenied`, mudança de papel.
   - Fluxo de 2FA TOTP (BR-804): as colunas existem, o fluxo não.
   - Convite por e-mail (pasta 18 §3): o estado `invited` existe, o convite não.

**5. Depois do Identity: módulo Catalog** — produtos, SKU (BR-002), preços varejo/atacado (BR-003), embalagens (BR-004). É o que o Estoque e as Vendas precisam existir antes.

## Decisões pendentes que não bloqueiam o código

- **ADR-0018** (cobrança boleto/PIX) — ainda `Proposto`, aguarda o dono e a resposta do cliente sobre o banco.
- **`Dona_Arteira_Gestao_desktop/`** — 34 arquivos ainda rastreados apesar de constarem no `.gitignore`. Decidir se saem do repositório (`git rm -r --cached`, o histórico preserva) ou se o `.gitignore` é que está errado.
- **Externas de lead longo:** pauta fiscal ao contador (`docs/13-Fiscal/01`), convênio bancário, chaves REST do Woo, dump do banco `dona_arteira` do desktop.
- **M-5** (backup automatizado), **M-8** (certificado A1 fora do webroot), **CA bundle** (`curl.cainfo`, antes do Gate 05).

## Armadilhas deste ambiente (já documentadas, para não repetir)

P-13 cron usa caminho absoluto · P-14 interpretador explícito + arquivo 644, nunca 777 · P-15 trocar versão de PHP troca `disable_functions` (revalidar sempre) · symlink: `ln -s` do shell funciona, `symlink()` do PHP não · hPanel gerencia cron por fora (`crontab -l` vazio) · comando longo no painel quebra (usar wrapper) · PHPStan estoura os 128 M do PHP CLI — usar `composer analyse`.
