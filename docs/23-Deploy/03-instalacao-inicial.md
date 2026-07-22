# 03 — Instalação Inicial (Gate 01, tarefa 1)

> **Status:** Em revisão — **aguardando execução** · **Última atualização:** 2026-07-22 · **Responsável:** devops-specialist
> **ADRs:** [0016](../27-ADR/ADR-0016-hospedagem.md) (plano Business) · [0019](../27-ADR/ADR-0019-inertia-substitui-spa.md) (Inertia) · [0001](../27-ADR/ADR-0001-monolito-modular.md) · [0002](../27-ADR/ADR-0002-mariadb.md) · [0014](../27-ADR/ADR-0014-fila-database.md)
> **Pré-requisito:** [validação de ambiente](01-validacao-ambiente-business.md) aprovada e [cron/docroot](02-verificar-cron-e-docroot.md) verificados

## 1. Objetivo

Levar o projeto do nada até um Laravel + Inertia rodando em `gestao.donaarteira.com.br`, com a estrutura de diretórios segura, o banco criado e o agendador ativo. É a primeira tarefa executável do Gate 01.

## 2. Passo 0 — revalidar o ambiente no PHP 8.4 🔴

A versão do PHP foi trocada de 8.2.30 para 8.4.19. **O conjunto de extensões pode diferir entre versões.** Reenviar e reexecutar:

```bash
php ~/validar-ambiente.php
```

> ### ✅ Executado em 2026-07-22 20:15 (PHP 8.4.19)
>
> **Extensões: todas presentes**, incluindo `soap`, `openssl`, a cadeia completa de XML, `zip`, `bcmath`, `intl` e `opcache`. Conectividade com a SEFAZ confirmada, MariaDB 11.8.8, `max_execution_time` ilimitado. **O Gate 05 é viável neste ambiente.**
>
> **⚠️ Uma regressão: `proc_open` está desabilitada no 8.4** (estava disponível no 8.2). No CloudLinux cada versão de PHP tem seu próprio `disable_functions`, e a lista padrão do alt-php84 é mais restritiva — note a assimetria de `proc_close` liberada e `proc_open` não.
>
> **Isto bloqueia o Passo 3 deste runbook** (`composer install` no servidor). Resolver antes de prosseguir: hPanel → configuração de PHP → `disable_functions` → remover `proc_open` → revalidar. Alternativas em [01 §7.5](01-validacao-ambiente-business.md#75-proc_open--o-que-depende-dela-e-o-que-fazer-p-15).

Confirmar também que web e CLI estão na mesma versão — divergência entre elas é fonte de bug que só aparece em produção.

> **Regra permanente:** neste ambiente, trocar a versão do PHP **troca a configuração inteira do interpretador**, incluindo `disable_functions`. Toda mudança de versão exige reexecutar a validação completa — não é uma mudança de número.

## 3. Passo 1 — criar o projeto (na máquina local)

O projeto **não** é criado no servidor. Desenvolvimento acontece localmente; o servidor recebe releases.

```bash
composer create-project laravel/react-starter-kit gestao-app
```

O *starter kit* React do Laravel 12 já entrega exatamente a stack decidida em [06-Frontend](../06-Frontend/README.md): Inertia, React, TypeScript, Vite, Tailwind e shadcn/ui, com autenticação por sessão pronta. Evita montar tudo peça por peça.

> Se preferir partir do esqueleto puro (`laravel/laravel`) e adicionar Inertia manualmente, o resultado deve ser equivalente ao descrito na pasta 06 — mas é mais trabalho sem ganho.

Verificar localmente que sobe (`php artisan serve`) antes de prosseguir.

## 4. Passo 2 — versionar

O repositório do projeto já existe (`main`, desde 2026-07-22) e hoje contém só documentação. O código entra nele.

```bash
# na raiz do repositório
git checkout -b gate-01/estrutura-inicial
# mover/copiar a aplicação para a raiz do repo (ou para app/, conforme a estrutura final)
git add -A && git commit -m "feat: esqueleto Laravel 12 + Inertia + React (Gate 01)"
```

**Antes do primeiro push, conferir o `.gitignore`:** `.env`, `/vendor/`, `/node_modules/`, `/public/build/` e `/storage/*.key` **nunca** entram. O `.gitignore` do projeto já cobre os principais — revisar após mover a aplicação.

> **Repositório remoto:** ainda não existe. Um repositório **privado** no GitHub é pré-requisito do CI/CD previsto no Gate 01. Criar antes do deploy, porque o deploy será por `git clone`/`git pull`.

## 5. Passo 3 — criar o banco de dados

No hPanel, seção de bancos MySQL. Criar:

| Banco | Uso |
|---|---|
| `..._erp` | aplicação |
| `..._erp_staging` | staging da migração ([pasta 17](../17-Migracao/README.md)) — se a cota do plano permitir |

Anotar host, nome, usuário e senha. Confirmar quantos bancos o plano permite (item M-7, ainda em aberto).

## 6. Passo 4 — montar a estrutura no servidor

Arranjo A, definido em [23/02 §3.3](02-verificar-cron-e-docroot.md#arranjo-a-recomendado--aplicação-fora-alcançada-por-link-simbólico):

```bash
BASE=~/domains/donaarteira.com.br

git clone git@github.com:SEU_USUARIO/SEU_REPO.git $BASE/gestao-app
cd $BASE/gestao-app

composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Editar o `.env` com os dados reais:

```ini
APP_ENV=production
APP_DEBUG=false                     # NUNCA true em produção
APP_URL=https://gestao.donaarteira.com.br
APP_TIMEZONE=America/Sao_Paulo

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=..._erp
DB_USERNAME=...
DB_PASSWORD=...

QUEUE_CONNECTION=database           # ADR-0014
SESSION_DRIVER=database
CACHE_STORE=database                # sem Redis neste plano
```

```bash
php artisan migrate --force
```

## 7. Passo 5 — os dois links simbólicos

`symlink()` do PHP está bloqueada, mas `ln -s` do shell funciona (verificado em 2026-07-22). Portanto **`artisan storage:link` falha** e o link é criado à mão.

```bash
BASE=~/domains/donaarteira.com.br

# 1) Document root do subdomínio → public/ da aplicação
rm -rf $BASE/public_html/gestao
ln -s ../gestao-app/public $BASE/public_html/gestao

# 2) Storage público (substitui o artisan storage:link)
ln -s ../storage/app/public $BASE/gestao-app/public/storage
```

Conferir: `ls -la $BASE/public_html/gestao` e `ls -la $BASE/gestao-app/public/storage`.

## 8. Passo 6 — permissões

```bash
cd ~/domains/donaarteira.com.br/gestao-app
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
chmod -R 775 storage bootstrap/cache
chmod 600 .env
```

> **Nunca `777`.** Além de desnecessário, este servidor roda suexec/CageFS, que **recusa executar arquivos graváveis por todos** ([P-14](01-validacao-ambiente-business.md#73-pendências-e-ressalvas)). O `.env` em `600` porque contém senhas.

## 9. Passo 7 — assets do frontend

**O build nunca roda no servidor.** Não há Node no plano, e `node_modules` consumiria dezenas de milhares de inodes de uma cota de 600.000 ([P-12](01-validacao-ambiente-business.md#73-pendências-e-ressalvas)).

```bash
# local ou CI
npm ci && npm run build
# enviar apenas o resultado
rsync -avz public/build/ usuario@host:~/domains/donaarteira.com.br/gestao-app/public/build/
```

O versionamento por hash do Vite resolve o cache-busting — não é preciso limpar cache do navegador a cada release.

## 10. Passo 8 — agendador e fila

Um único cron, com **interpretador explícito e caminho absoluto** ([P-13](01-validacao-ambiente-business.md#73-pendências-e-ressalvas)/[P-14](01-validacao-ambiente-business.md#73-pendências-e-ressalvas)):

```
* * * * * /usr/bin/php /home/u917402451/domains/donaarteira.com.br/gestao-app/artisan schedule:run >> /dev/null 2>&1
```

O `schedule:run` dispara o processamento da fila, definido em `routes/console.php`. O cron de 1 minuto foi medido e confirmado (mediana de 60 s).

> Conferir com `crontab -l` que existe **apenas um** job. Um `schedule:run` duplicado processaria cada job duas vezes — inaceitável num ledger de estoque ([ADR-0008](../27-ADR/ADR-0008-ledger-estoque.md)).

## 11. Passo 9 — teste dos canários (aceite de segurança) 🔴

Sem estes seis resultados, **não há release**. Procedimento completo em [23/02 §3.4](02-verificar-cron-e-docroot.md#34-passo-3--o-teste-dos-canários).

```bash
for u in \
  "https://gestao.donaarteira.com.br/" \
  "https://gestao.donaarteira.com.br/.env" \
  "https://gestao.donaarteira.com.br/composer.json" \
  "https://donaarteira.com.br/gestao-app/.env" \
  "https://donaarteira.com.br/gestao/.env" \
  "https://donaarteira.com.br/gestao/storage/logs/laravel.log" ; do
  printf "%-72s %s\n" "$u" "$(curl -s -o /dev/null -w '%{http_code}' "$u")"
done
```

Esperado: **200 na primeira, 404 (ou 403) em todas as outras.**

## 12. Passo 10 — verificação final

| # | Verificar | Como |
|---|---|---|
| 1 | A aplicação abre | `https://gestao.donaarteira.com.br` |
| 2 | `APP_DEBUG=false` | uma rota inexistente mostra erro genérico, sem stack trace |
| 3 | HTTPS com certificado válido | cadeado no navegador |
| 4 | Banco conectado | `php artisan migrate:status` |
| 5 | Fila funcionando | despachar um job de teste e ver o processamento em ≤ 2 min |
| 6 | Logs graváveis | `storage/logs/laravel.log` cresce |
| 7 | WordPress intacto | `https://donaarteira.com.br` continua normal |
| 8 | Canários | §11, todos aprovados |

## 13. Dependências

| Depende de | Situação |
|---|---|
| [Validação de ambiente](01-validacao-ambiente-business.md) | ✅ aprovada (revalidar no 8.4 — §2) |
| [Cron e document root](02-verificar-cron-e-docroot.md) | ✅ verificados |
| Repositório remoto privado | ⏳ a criar |
| Banco de dados no hPanel | ⏳ a criar |

## 14. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| `soap` não existir no PHP 8.4 | Baixa | **Crítico** | Passo 0 antes de tudo; saídas registradas lá |
| `.env` versionado por engano | Média | **Crítico** | Revisar `.gitignore` antes do primeiro push; canário 2 do §11 |
| `APP_DEBUG=true` em produção | Média | Alto | Item 2 da verificação final; expõe configuração e caminhos |
| Build de assets rodar no servidor e estourar inodes | Média | Alto | §9 — o build é sempre local ou no CI |
| Deploy quebrar o WordPress | Baixa | Alto | Item 7 da verificação final; as árvores são separadas por construção |
| `git clone` trazer o histórico inteiro e pesar em inodes | Baixa | Médio | `--depth 1` se necessário; o repositório é leve (1,2 MB) |

## 15. Evoluções futuras

- Automatizar os passos 4 a 9 em workflow do GitHub Actions (entrega de CI/CD do Gate 01).
- Deploy atômico por troca de link simbólico — viável, já que `ln -s` funciona no shell.
- Runbook de rollback, derivado do deploy atômico.
