# 04 — Atualizar a produção

> **Status:** Em uso · **Última atualização:** 2026-07-23 · **Responsável:** devops-specialist
> **Pré-requisito:** a [instalação inicial](03-instalacao-inicial.md) já foi feita — este runbook é para **releases seguintes**
> **ADRs:** [0014](../27-ADR/ADR-0014-fila-database.md) (fila por cron) · [0016](../27-ADR/ADR-0016-hospedagem.md) (hospedagem)

## 1. Objetivo

Publicar uma versão nova em `gestao.donaarteira.com.br` sem derrubar a operação e sem esquecer nenhum passo.

O pipeline automatizado descrito no [README da pasta](README.md#3-pipeline-github-actions) — deploy por Deployer, staging automático, health check — **ainda não existe**. Hoje o deploy é manual, por SSH. Este documento é o que se executa de fato até lá; a distância entre os dois é dívida registrada, não descuido.

## 2. O que é fácil esquecer

**Acesso ao servidor:** `ssh -p 65002 u917402451@147.93.39.76`. A porta
65002 é a padrão da Hostinger — sem o `-p` a conexão simplesmente expira.

| Passo | Consequência de pular |
|---|---|
| `npm run build` **local** + envio dos assets | O servidor serve os assets da versão anterior. A tela quebra de formas confusas, porque o HTML é novo e o JS é velho |
| `php artisan db:seed --force` | Papéis e permissões novos não existem no banco. Todo mundo passa a receber 403 no que foi adicionado — inclusive o admin, porque a permissão não existe para ser concedida |
| `config:cache` **depois** de editar o `.env` | O `.env` novo é ignorado; a aplicação continua com a configuração antiga em cache |
| Backup antes de migration | Migration com `down()` quebrado vira restauração de dump — se houver dump |

## 3. Procedimento

### 3.1 Na máquina local — construir os assets

```bash
cd gestao-app
npm ci
npm run build
```

**O build nunca roda no servidor.** Não há Node no plano, e `node_modules` consumiria dezenas de milhares de inodes de uma cota de 600.000 ([P-12](01-validacao-ambiente-business.md#73-pendências-e-ressalvas)).

Conferir que a suíte está verde antes de publicar qualquer coisa:

```bash
php artisan test && composer analyse && vendor/bin/pint --test && npm run test:ci
```

### 3.2 No servidor — backup

```bash
BASE=~/domains/donaarteira.com.br
APP=$BASE/erp/gestao-app

mysqldump -u u917402451_erp -p u917402451_da_erp \
  | gzip > ~/backups/erp-$(date +%Y%m%d-%H%M%S).sql.gz \
  && ls -la ~/backups/ | grep erp-
```

O `-p` **sem valor colado** é proposital: o mysqldump pergunta a senha no
terminal, que é o único jeito de ela não passar pelo histórico do shell.
Rodar **dentro** da sessão SSH, não como argumento de um `ssh "..."` de
fora — o `$(date ...)` precisaria de escape e é onde a linha quebra.

> **Confira o tamanho, sempre.** `mysqldump | gzip` num pipe **não
> falha**: se o dump não sai, o gzip grava mesmo assim e o arquivo fica
> com **20 bytes** — um gzip vazio, com cara de backup. Aconteceu em
> 2026-07-24, duas vezes. Um dump real deste banco tem alguns milhares de
> bytes. É por isso que o `&& ls -la` faz parte do comando.

Backup que nunca foi restaurado não é backup — o teste de restore é trimestral e tem runbook próprio (pendência **M-5**).

### 3.3 No servidor — atualizar o código

```bash
cd $BASE/erp
git pull
cd $APP
composer install --no-dev --optimize-autoloader
```

> O clone é *sparse* (`git sparse-checkout set gestao-app`): `git pull` traz só a aplicação. Se o `pull` reclamar de alteração local, **não** resolver com `git checkout --` sem olhar o que mudou — pode ser um hotfix aplicado à mão que ainda não voltou para o repositório.

### 3.4 Da máquina local — enviar os assets

**Não use `rsync`:** ele não existe no Git Bash do Windows, que é o
shell da máquina de desenvolvimento. Use `scp`, que vem junto com o SSH.

Envio em duas etapas para não haver instante em que `build/` esteja
incompleto — durante um `scp` direto sobre a pasta em uso, um acesso à
aplicação pegaria metade dos assets novos e metade dos velhos:

```bash
SRV=u917402451@147.93.39.76
APP=domains/donaarteira.com.br/erp/gestao-app

# 1) sobe para uma pasta ao lado
ssh -p 65002 $SRV "rm -rf ~/$APP/public/build.new"
scp -P 65002 -r public/build "$SRV:~/$APP/public/build.new"

# 2) troca de lugar (rápido) e guarda a anterior para rollback
ssh -p 65002 $SRV "cd ~/$APP/public \
  && rm -rf build.old \
  && mv build build.old \
  && mv build.new build"
```

Rollback dos assets: `mv build build.new && mv build.old build`.

> **Por que não `scp` direto por cima:** os nomes dos arquivos têm hash,
> então os antigos não seriam sobrescritos — ficariam acumulando na pasta
> release após release. O `manifest.json` aponta só para os novos, mas o
> lixo cresce sem que ninguém perceba.

### 3.5 No servidor — banco e caches

```bash
cd $APP

php artisan down --render="errors::503"   # só se houver migration pesada

php artisan migrate --force
php artisan db:seed --force               # seeders de referência, idempotentes

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan up
```

**Por que `db:seed` em todo deploy:** os seeders de referência (papéis, permissões, categorias financeiras, perfis fiscais) são idempotentes e convergentes por decisão — ver [pasta 04, regra 5](../04-Banco-de-Dados/02-convencoes-de-banco.md#migrations-regras-para-o-gate-01) e o `RolePermissionSeeder`. Rodá-los sempre é o que mantém a matriz da [pasta 19](../19-Permissoes/README.md) valendo em produção. Um seeder que duplicasse dados ao ser reexecutado transformaria o deploy em operação manual — por isso a idempotência é testada.

### 3.6 Verificar

| # | Verificar | Como |
|---|---|---|
| 1 | A aplicação abre | `https://gestao.donaarteira.com.br` |
| 2 | Assets na versão certa | Devtools → Network: os arquivos de `build/` têm o hash novo |
| 3 | Sem stack trace | Rota inexistente mostra erro genérico (`APP_DEBUG=false`) |
| 4 | Migrations aplicadas | `php artisan migrate:status` |
| 5 | Agendador vivo | `cat ~/scheduler-ultima-execucao.txt` — o `scheduler.sh` grava ali a cada execução. **Não use `~/schedule.log`:** o hPanel não honra o redirecionamento escrito no campo de comando do cron, então esse arquivo nunca existe, mesmo com o cron rodando |
| 6 | Logs graváveis | `php artisan tinker --execute='Log::error("canario");'` e conferir `storage/logs/laravel.log`. Passou a funcionar em 2026-07-24 — mas a correção do P-16 pode ser desfeita pelo painel, então **verificar em todo deploy**, não presumir. Ver §7 |
| 7 | WordPress intacto | `https://donaarteira.com.br` |

## 4. Rollback

```bash
cd $BASE/erp
git log --oneline -5          # achar o commit anterior
git checkout <commit-anterior>
cd $APP && composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache
```

E restaurar os assets: `cd ~/$APP/public && mv build build.new && mv build.old build`. Se a versão anterior já tiver sido sobrescrita duas vezes, reconstruir localmente a partir do mesmo commit e reenviar pela §3.4.

**Migration não volta sozinha.** `migrate:rollback` desfaz o último *batch* — o CI verifica que os `down()` funcionam ([regra 2 da pasta 04](../04-Banco-de-Dados/02-convencoes-de-banco.md#migrations-regras-para-o-gate-01)), mas migration destrutiva exige restaurar o dump da §3.2. A regra de expand/contract existe justamente para tornar isso raro.

## 5. Primeira entrada num ambiente novo

O `AdminInicialSeeder` cria a conta admin na primeira execução e **imprime a senha provisória uma única vez no console** — a menos que `ADMIN_INICIAL_SENHA` esteja definida no `.env`. Anotar na hora: ela não vai para log nenhum. A troca é obrigatória no primeiro acesso.

Reexecutar o seeder num banco que já tem admin **não** redefine senha alguma.

## 6. Conferir antes de confiar no `.env` do servidor

O primeiro deploy real (2026-07-23) encontrou a produção em dois estados
que a documentação afirmava resolvidos. Ambos passariam despercebidos
porque a aplicação **respondia normalmente**:

```bash
cd ~/domains/donaarteira.com.br/erp/gestao-app

# Nunca use `grep '^DB_'` — isso imprime a senha na tela e no histórico.
grep -E '^(APP_ENV|APP_DEBUG|APP_URL|DB_CONNECTION|DB_HOST|DB_DATABASE)=' .env

# Chaves duplicadas: dotenv aceita, a última vence, e alguém no futuro
# edita a errada.
grep -oE '^[A-Za-z_][A-Za-z0-9_]*=' .env | sort | uniq -d

# A conexão que o Laravel realmente usa, não a que o .env parece dizer.
php artisan tinker --execute='echo config("database.default");'
```

| O que estava errado | Como se manifestava | Por que passou |
|---|---|---|
| `DB_CONNECTION=sqlite` com `DB_DATABASE=u917402451_da_erp` | O Laravel tratou o nome do banco MySQL como **caminho de arquivo** e criou um SQLite chamado `u917402451_da_erp` na raiz da aplicação. O MariaDB de produção estava **vazio** | A tela abria; `migrate:status` dizia "Ran". Ninguém perguntou *em qual banco* |
| `APP_DEBUG=true` | Nenhuma, até o primeiro erro — e aí a stack trace renderiza o conteúdo do `.env` | Só aparece quando algo quebra |

**A lição não é "conferir o `.env`", é conferir o comportamento.**
`migrate:status` respondendo "Ran" não diz contra qual banco; a única
pergunta que revela isso é `config("database.default")`.

## 7. ✅ P-16 — a extensão `psr` quebrava todo o log da aplicação

**Descoberto em 2026-07-23, durante o primeiro deploy real. Corrigido em
2026-07-24 — mas por um caminho diferente do que esta seção previa, e a
correção não é definitiva. Ver "Como foi corrigido de fato", abaixo.**

O servidor carrega a extensão PHP **`psr`** (`extension=psr.so`, linha
193 de `/opt/alt/php84/link/conf/alt_php.ini`), em **ambos os SAPIs** —
CLI e LiteSpeed. Ela declara `Psr\Log\LoggerInterface` nativamente, com a
assinatura **antiga** do PSR-3 (`$message` sem tipo). O Monolog 3, que o
Laravel 12 usa, declara `emergency(Stringable|string $message)`.

O resultado é erro **fatal** na declaração da classe:

```
Fatal error: Declaration of Monolog\Logger::emergency(Stringable|string $message,
array $context = []): void must be compatible with
PsrExt\Log\LoggerInterface::emergency($message, array $context = [])
```

### Por que isto é grave e ao mesmo tempo invisível

A aplicação funciona normalmente — **até o primeiro erro**. O Laravel
monta o logger sob demanda, então enquanto nada é registrado, nada
quebra. Quando uma exceção acontece, o *handler* tenta logá-la, o
Monolog é carregado, e o processo morre. O visitante recebe **500 em
branco** e **nada é gravado em lugar nenhum** — nem em `laravel.log`, nem
no log do servidor.

Ou seja: o mecanismo que existe para explicar falhas é o próprio que
falha. Um ERP com estoque, dinheiro e NF-e não pode operar assim.

### Como foi comprovado (não deduzido)

Dois arquivos idênticos em `public/`, diferindo apenas por uma linha:

| Arquivo | Conteúdo | Resposta |
|---|---|---|
| A | `extension_loaded('psr')` | **200** — imprime `sapi=litespeed psr=SIM` |
| B | o mesmo + `new Monolog\Logger('x')` | **500** |

### O que não resolve

- `.user.ini` não desliga extensão (`extension` é `PHP_INI_SYSTEM`).
- `php -d` só afeta o CLI.
- **Todos** os canais de log do Laravel passam por Monolog — inclusive
  `null`, `errorlog` e `stderr`. Não existe canal que escape.
- Fixar `psr/log` em 1.x quebraria Laravel 12 e Monolog 3.

### Como foi corrigido de fato (2026-07-24)

O caminho previsto era **hPanel → PHP Configuration → Extensions →
desmarcar `psr`**. Ele foi tentado duas vezes e **não funcionou**: depois
de desmarcar no painel, o `extension=psr.so` continuava na linha 193 do
`alt_php.ini` e o `mtime` do arquivo nem sequer mudava. O painel aceita a
ação e não a aplica.

O que resolveu foi editar o ini **direto por SSH**. A descoberta que
destravou: apesar de morar em `/opt`, o arquivo é **do usuário** e
gravável por ele.

```bash
stat -c 'dono=%U perm=%A' /opt/alt/php84/link/conf/alt_php.ini
# dono=u917402451 perm=-rw-r--r--

cp /opt/alt/php84/link/conf/alt_php.ini ~/backups/alt_php.ini-$(date +%Y%m%d-%H%M%S).bak
sed -i 's/^extension=psr\.so/;extension=psr.so/' /opt/alt/php84/link/conf/alt_php.ini
```

Reverter é descomentar a linha.

> ⚠️ **A correção não é definitiva.** O painel reescreve esse arquivo
> quando se mexe em configuração de PHP — foi visto reescrevendo-o em
> 2026-07-23, às 13:45 e às 14:29. Uma ação futura no hPanel pode trazer
> o `extension=psr.so` de volta sem aviso. **Reconferir depois de
> qualquer mexida em PHP no painel**, e não só na troca de versão que a
> [P-15](01-validacao-ambiente-business.md#73-pendências-e-ressalvas)
> já mandava reconferir.

### Verificação

CLI — feito em 2026-07-24, resultado registrado ao lado:

```bash
php -r 'var_dump(extension_loaded("psr"));'   # bool(false) ✅
cd ~/domains/donaarteira.com.br/erp/gestao-app
php artisan tinker --execute='Log::error("canario"); echo "ok";'   # ok ✅
tail -1 storage/logs/laravel.log
# [2026-07-24 16:16:10] production.ERROR: canario P-16 2026-07-24 ✅
```

Essa foi a **primeira linha que o `laravel.log` da produção recebeu** —
antes disso, o arquivo nunca fora escrito, porque nunca conseguiu ser.

SAPI web — **ainda não verificado positivamente.** O teste previsto (subir
arquivo temporário em `public/` que instancie `Monolog\Logger` e conferir
200) não pôde ser executado nesta sessão. O que se sabe: os workers
`lsphp` iniciados **depois** da edição do ini já nascem sem a extensão
(comparar `ps -o pid,lstart -u $(id -u) | grep lsphp` com o `mtime` do
ini), e os antigos morrem por reciclagem. Fechar essa verificação no
próximo deploy.

> Esta armadilha entra na lista permanente do ambiente como **P-16**, ao
> lado de P-13/P-14/P-15.

## 8. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Esquecer o envio dos assets | **Alta** | Alto | §2 abre com isso; o item 2 da §3.6 verifica pelo hash |
| Deploy sem `db:seed` deixar permissão nova sem existir | Média | Alto | §3.5 e a explicação do porquê |
| `git pull` sobrescrever hotfix manual do servidor | Baixa | Alto | Nota da §3.3; hotfix manual deve virar commit no mesmo dia |
| Deploy manual divergir do que o CI testou | Média | Alto | Automatizar o deploy (dívida do README §3); até lá, publicar só o que está em `main` com CI verde |
| `composer install` falhar em `package:discover` por `proc_open` desabilitada ([P-15](01-validacao-ambiente-business.md#73-pendências-e-ressalvas)) | **Alta** | Médio | Os pacotes instalam mesmo assim; só o discover falha, porque o composer o invoca via `Process`. Rodar `php artisan package:discover` **direto** resolve sem depender de `proc_open`. A causa raiz (o painel reverter `disable_functions`) é do ambiente |
| `.env` de produção divergir do que a documentação afirma | **Alta** | **Crítico** | §6 — conferir comportamento, não o arquivo |
| **Extensão `psr` derrubar toda requisição que precise logar** | Média — corrigida em 2026-07-24, mas o painel pode reverter | **Crítico** | §7 (P-16) — a linha está comentada no `alt_php.ini`; o item 6 da §3.6 reconfere a cada deploy, porque sem log nenhuma falha de produção é diagnosticável |
| Painel aceitar uma configuração de PHP e não aplicá-la | Média | Alto | Aconteceu com o `psr` (§7): desmarcar no hPanel não mexeu no `alt_php.ini`. **Conferir o efeito no servidor**, nunca a tela do painel |
| Agendador parecer parado porque `schedule.log` não existe | Média | Baixo | Falso alarme: o hPanel não honra o redirecionamento do comando. O batimento vive em `~/scheduler-ultima-execucao.txt`, escrito pelo próprio `scheduler.sh` |
