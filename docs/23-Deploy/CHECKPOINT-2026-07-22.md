# Checkpoint — 2026-07-22

> Ponto de retomada do trabalho. Não é documento canônico de arquitetura — é um "onde paramos e o que fazer a seguir". Apagar quando o Gate 01 tiver seu próprio acompanhamento.

## Onde chegamos hoje

Saímos de "documentação não versionada + decisões travadas" para **um ERP Laravel + Inertia no ar em produção**, com as dependências dos ADRs instaladas localmente. 24+ commits na branch `main`.

### Ambiente de produção (Hostinger Business) — ✅ operacional

| Item | Estado |
|---|---|
| App no ar | ✅ `https://gestao.donaarteira.com.br` (tela Welcome do Laravel) |
| Estrutura segura | ✅ Arranjo A por symlink: app em `~/domains/donaarteira.com.br/erp/gestao-app`, `public_html/gestao` → `../erp/gestao-app/public`. 6 canários aprovados |
| Banco produção | ✅ `u917402451_da_erp` (usuário `u917402451_erp`), migração base rodada |
| Banco staging | ✅ `u917402451_erp_staging` (usuário `u917402451_staging`) |
| Agendador | ✅ cron `* * * * * /bin/bash ~/scheduler.sh` ativo, confirmado |
| PHP produção | ✅ 8.4.19, `soap`+`openssl`+cadeia XML presentes, `proc_open` reabilitada |
| Deploy key GitHub | ✅ somente-leitura, servidor clona por SSH |
| Repo remoto | `github.com/diegocarlesso/Gestao_Dona_Arteira` (privado) |

### Ambiente local (máquina do Diego) — ✅ pronto

| Item | Estado |
|---|---|
| PHP | ✅ 8.4.23 via **Herd** (XAMPP 8.2 permanece só para o MySQL do legado). `php.ini` do Herd corrigido: extensões + timezone |
| Composer | ✅ funcionando (voltou ao PATH) |
| Dependências dos ADRs | ✅ instaladas — ver lista abaixo |
| `npm install` | ⏳ estava rodando no fim da sessão — **confirmar que concluiu** |

### Dependências instaladas (composer.lock, confirmadas)

sanctum 4.3.3 · laravel-permission 8.3.0 · laravel-auditing 14.0.6 · brick/money 0.11.2 · laravel-query-builder 7.3.0 · pest 3.8.7 · pest-plugin-laravel 3.2.0 · **pest-plugin-arch 3.1.1** (executa o ADR-0020) · larastan 3.10.0

## Decisões tomadas nesta sessão (todas com ADR)

- **ADR-0016** — Hospedagem: plano Business (Plano B; contraria recomendação técnica, gatilhos ativos). **Validado pelos fatos** — ambiente suporta NF-e.
- **ADR-0018** — Cobrança (boleto/PIX) via adapter plugável. ⚠️ ainda Proposto — aguarda decisão do dono (fase 7 → Gate 04) e resposta do cliente sobre banco.
- **ADR-0019** — Laravel + Inertia + React (substitui a SPA separada do ADR-0004). Economia estimada 200–350 h.
- **ADR-0020** — Fronteiras entre módulos verificadas por `arch()`; migrations centralizadas em `database/migrations/`; testes em `tests/`.

## PRÓXIMO PASSO (retomar exatamente aqui)

**1. Confirmar o `npm install` e rodar a suíte pela primeira vez** (no PowerShell, com php = Herd 8.4):

```powershell
# criar o banco de teste uma vez, no MySQL do XAMPP:
#   CREATE DATABASE erp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan test        # deve rodar Pest, incluindo tests/Architecture/ModulesTest.php
vendor/bin/phpstan analyse
npm run build           # confirma que o Vite compila
```

Resultado esperado: verde. Os testes do starter kit ainda são classes PHPUnit (o Pest as executa normalmente). Se `php artisan test` reclamar de conexão, é o banco `erp_test` que falta criar.

**2. Publicar e rodar as migrations dos pacotes** (ainda NÃO publicadas):

```powershell
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="OwenIt\Auditing\AuditingServiceProvider" --tag="auditing-migrations"
# sanctum: migration já vem em vendor; publicar config se necessário
php artisan migrate
```

**3. Alinhar o CI e limpar o repositório:**
- Empurrar os commits locais (o CI vai rodar Pest/PHPStan/arch tests — deve ficar verde agora que as deps existem).
- Decidir sobre `Dona_Arteira_Gestao_desktop/` (34 arquivos ainda rastreados apesar do .gitignore — `git rm -r --cached` se for para sair).
- Mover/remover os PNGs da raiz (`ok.png`, `subdomain.png`, `cron.php` que é PNG).
- Considerar mover os commits de código de `main` para branch de feature, se quiser fluxo por PR.

**4. Primeiro módulo: Identity** (auth + RBAC + auditoria) — a fundação de catálogo/clientes/estoque.
- Estrutura `app/Modules/Identity/` conforme ADR-0020 e pasta 05.
- Diego pediu que eu deixasse o Identity **pré-construído** para ele rodar após o update — combinar se faço isso primeiro.

## Pendências não-bloqueantes registradas

- **Externas de lead longo (disparar assim que possível):** pauta fiscal ao contador (`docs/13-Fiscal/01`), convênio bancário para boleto, chaves REST do Woo, dump do banco `dona_arteira` do desktop.
- **M-5** (backup automatizado) e **M-8** (local do certificado A1 fora do webroot) — validação de ambiente, não urgentes.
- **CA bundle** (`curl.cainfo`) no servidor — configurar antes do Gate 05 (NF-e).
- Confirmar se o `schedule.log` cresce por arquivo ou só pelo "View Output" do painel (irrelevante — agendador comprovadamente ativo).

## Armadilhas deste ambiente (já documentadas, para não repetir)

P-13 cron usa caminho absoluto · P-14 interpretador explícito + arquivo 644, nunca 777 · P-15 trocar versão de PHP troca `disable_functions` (revalidar sempre) · symlink: `ln -s` do shell funciona, `symlink()` do PHP não · hPanel gerencia cron por fora (`crontab -l` vazio) · comando longo no painel quebra (usar wrapper).
