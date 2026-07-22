# 03 — Instalação Inicial (Gate 01, tarefa 1)

> **Status:** ⏳ **Em execução** — aplicação no ar em `gestao.donaarteira.com.br` desde 2026-07-22, canários aprovados. Pendem banco, permissões e cron (§12) · **Última atualização:** 2026-07-22 · **Responsável:** devops-specialist
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

A aplicação vive no subdiretório **`gestao-app/`** do repositório, ao lado de `docs/`. Essa separação é deliberada: documentação e código versionados juntos (exigência do projeto), mas distinguíveis na hora de publicar.

```bash
git checkout -b gate-01/estrutura-inicial
git add -A && git commit -m "feat: esqueleto Laravel 12 + Inertia + React (Gate 01)"
git push -u origin gate-01/estrutura-inicial
```

**Antes do push, conferir o `.gitignore`:** `.env`, `/vendor/`, `/node_modules/`, `public/build/` e chaves **nunca** entram.

> ⚠️ **`.gitignore` não desrastreia o que já foi commitado.** Se um arquivo já está no índice, adicioná-lo ao `.gitignore` não tem efeito — é preciso `git rm -r --cached <caminho>` e commitar a remoção. Verificar com `git ls-files | grep <padrão>`.

**Repositório remoto:** `github.com/diegocarlesso/Gestao_Dona_Arteira`. Deve ser **privado** — contém análise crítica, modelo de custos e o inventário do legado.

## 5. Passo 3 — criar o banco de dados

No hPanel, seção de bancos MySQL.

> ### ✅ Criados em 2026-07-22
>
> | Banco | Usuário | Uso |
> |---|---|---|
> | `u917402451_da_erp` | `u917402451_erp` | aplicação |
> | `u917402451_erp_staging` | `u917402451_staging` | staging da migração ([pasta 17](../17-Migracao/README.md)) |
>
> Item **M-7** resolvido: o plano comporta ao menos dois bancos.

**Regras sobre as credenciais** (pasta [25-Segurança](../25-Seguranca/README.md)):

- Senhas vivem **apenas** no `.env` do servidor (`chmod 600`) — nunca em documento, mensagem, issue ou commit.
- **Senhas distintas** para produção e staging. O staging recebe dados do legado e é manipulado com menos cerimônia; credencial compartilhada transforma um incidente lá em incidente aqui.
- Senha exposta acidentalmente (mensagem, print, log) é **rotacionada**, não "vigiada".
- No `.env`, envolver o valor em aspas evita que caracteres especiais quebrem o parser:
  ```ini
  DB_PASSWORD="sua-senha-aqui"
  ```

## 6. Passo 4 — montar a estrutura no servidor

Arranjo A, definido em [23/02 §3.3](02-verificar-cron-e-docroot.md#arranjo-a-recomendado--aplicação-fora-alcançada-por-link-simbólico).

### 6.1 Autenticação do servidor no GitHub (deploy key)

O servidor precisa de credencial própria para clonar. Sem ela:

```
git@github.com: Permission denied (publickey).
```

**Use uma deploy key somente-leitura**, não a sua chave pessoal: ela vale para **um único repositório**, não dá acesso à sua conta, e é revogável isoladamente se o servidor for comprometido.

```bash
# 1. Gerar o par de chaves no servidor (sem passphrase — o deploy é automatizado)
ssh-keygen -t ed25519 -C "deploy@gestao.donaarteira.com.br" \
           -f ~/.ssh/id_ed25519_gestao -N ""

# 2. Exibir a chave PÚBLICA para copiar
cat ~/.ssh/id_ed25519_gestao.pub
```

No GitHub: **repositório → Settings → Deploy keys → Add deploy key**. Colar a chave pública e **deixar "Allow write access" DESMARCADO** — o servidor só lê; quem escreve é a sua máquina.

```bash
# 3. Dizer ao SSH qual chave usar para o GitHub
cat >> ~/.ssh/config <<'CFG'

Host github.com
    HostName github.com
    User git
    IdentityFile ~/.ssh/id_ed25519_gestao
    IdentitiesOnly yes
CFG

# 4. Permissões — o SSH recusa chaves com permissão frouxa
chmod 700 ~/.ssh
chmod 600 ~/.ssh/id_ed25519_gestao ~/.ssh/config
chmod 644 ~/.ssh/id_ed25519_gestao.pub

# 5. Testar
ssh -T git@github.com
```

Resposta esperada (a mensagem sobre shell é normal):

```
Hi diegocarlesso/Gestao_Dona_Arteira! You've successfully authenticated,
but GitHub does not provide shell access.
```

> **Alternativa:** HTTPS com *personal access token*. Funciona, mas o token fica gravado no servidor, costuma ter escopo mais amplo que um repositório e expira — gerando falha de deploy no pior momento. A deploy key é melhor em todos os aspectos.

### 6.2 Clonar e instalar

O repositório contém **mais do que a aplicação**: `docs/`, `.claude/`, `.planning/` e o sistema desktop legado. A aplicação Laravel vive no subdiretório **`gestao-app/`**. Portanto o clone vai para `$BASE/erp`, e o `public/` da aplicação fica em `$BASE/erp/gestao-app/public`.

```bash
BASE=~/domains/donaarteira.com.br
REPO=git@github.com:diegocarlesso/Gestao_Dona_Arteira.git
```

**Opção recomendada — clone parcial (só a aplicação):**

```bash
git clone --filter=blob:none --sparse $REPO $BASE/erp
cd $BASE/erp
git sparse-checkout set gestao-app
```

Traz apenas `gestao-app/` para o servidor. A configuração persiste: `git pull` continua funcionando normalmente nos releases seguintes. Mantém em produção só o que roda — a documentação (incluindo modelo de custos e análise crítica, que são material interno) fica fora.

**Alternativa — clone completo:**

```bash
git clone $REPO $BASE/erp
```

Mais simples, ao custo de manter documentação e o sistema legado no servidor. Não há exposição pela web (tudo fica fora do `public/`), mas é material que não precisa estar lá.

```bash
cd $BASE/erp/gestao-app

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
ln -s ../erp/gestao-app/public $BASE/public_html/gestao

# 2) Storage público (substitui o artisan storage:link)
ln -s ../storage/app/public $BASE/erp/gestao-app/public/storage
```

Conferir que os dois links resolvem:

```bash
ls -la $BASE/public_html/gestao
ls -la $BASE/erp/gestao-app/public/storage
cat $BASE/public_html/gestao/index.php | head -3   # deve mostrar o index.php do Laravel
```

## 8. Passo 6 — permissões

```bash
cd ~/domains/donaarteira.com.br/erp/gestao-app
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
rsync -avz public/build/ usuario@host:~/domains/donaarteira.com.br/erp/gestao-app/public/build/
```

O versionamento por hash do Vite resolve o cache-busting — não é preciso limpar cache do navegador a cada release.

## 10. Passo 8 — agendador e fila

Um único cron, com **interpretador explícito e caminho absoluto** ([P-13](01-validacao-ambiente-business.md#73-pendências-e-ressalvas)/[P-14](01-validacao-ambiente-business.md#73-pendências-e-ressalvas)):

```
* * * * * /usr/bin/php /home/u917402451/domains/donaarteira.com.br/erp/gestao-app/artisan schedule:run >> /dev/null 2>&1
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
  "https://donaarteira.com.br/erp/.env" \
  "https://donaarteira.com.br/gestao/.env" \
  "https://donaarteira.com.br/gestao/storage/logs/laravel.log" ; do
  printf "%-72s %s\n" "$u" "$(curl -s -o /dev/null -w '%{http_code}' "$u")"
done
```

Esperado: **200 na primeira, 404 (ou 403) em todas as outras.**

> ### ✅ Executado em 2026-07-22 — APROVADO
>
> ```
> https://gestao.donaarteira.com.br/                              200
> https://gestao.donaarteira.com.br/.env                          404
> https://gestao.donaarteira.com.br/composer.json                 404
> https://donaarteira.com.br/erp/.env                             404
> https://donaarteira.com.br/gestao/.env                          404
> https://donaarteira.com.br/gestao/storage/logs/laravel.log      403
> ```
>
> A aplicação responde e **nenhum arquivo interno é alcançável por qualquer das duas origens**. O Arranjo A cumpriu o que prometia: o link simbólico é atravessável, mas não permite subir a árvore.
>
> O `403` da última linha é aceite válido (acesso negado). Um `404` seria marginalmente melhor por não revelar que há algo protegido ali — vale conferir se o link `public/storage` foi criado, já que sua ausência pode explicar a diferença.

O que cada linha prova:

| # | URL | Prova |
|---|---|---|
| 1 | raiz do subdomínio | O link simbólico funciona e o Laravel responde |
| 2 | `.env` pelo subdomínio | O docroot é `public/`, não a raiz da aplicação |
| 3 | `composer.json` pelo subdomínio | idem — nenhum arquivo de projeto vaza |
| 4 | `/erp/.env` pelo domínio principal | O clone está **fora** de `public_html`, inalcançável pelo WordPress |
| 5 | `/gestao/.env` pelo domínio principal | **O mais importante:** o link é atravessável, mas resolve para `public/` — não dá para subir a árvore por ele |
| 6 | log pelo domínio principal | `storage/` inalcançável mesmo através do link |

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

## 15. Dependências ainda a instalar (exigidas pelos ADRs)

O *starter kit* entrega a base, mas não os pacotes que os ADRs do projeto determinam. Instalar antes de escrever o primeiro módulo:

| Pacote | Por quê | Origem |
|---|---|---|
| `pestphp/pest` + `pest-plugin-laravel` | **Regra 8 do projeto:** backend testa com Pest. O kit vem com PHPUnit | [22-Testes](../22-Testes/README.md) |
| `laravel/sanctum` | Tokens de integração (webhooks, scripts, parceiros) | [ADR-0005](../27-ADR/ADR-0005-autenticacao-sanctum.md) |
| `spatie/laravel-permission` | RBAC deny-by-default | [ADR-0011](../27-ADR/ADR-0011-rbac.md) |
| `owen-it/laravel-auditing` | Trilha de auditoria | [ADR-0012](../27-ADR/ADR-0012-auditoria.md) |
| `brick/money` | Dinheiro sem float | [ADR-0013](../27-ADR/ADR-0013-dinheiro-decimal.md) |
| `spatie/laravel-query-builder` | Filtros/ordenação da API de integração | [07-API §5](../07-API/README.md) |
| `vitest` + Testing Library | Testes de componente | [06-Frontend §7](../06-Frontend/README.md) |

> `composer.json` declara `php: ^8.2`. O servidor roda 8.4.19 — compatível, mas vale alinhar para `^8.4` para que o CI valide na mesma versão de produção.

## 16. Evoluções futuras

- Automatizar os passos 4 a 9 em workflow do GitHub Actions (entrega de CI/CD do Gate 01).
- Deploy atômico por troca de link simbólico — viável, já que `ln -s` funciona no shell.
- Runbook de rollback, derivado do deploy atômico.
