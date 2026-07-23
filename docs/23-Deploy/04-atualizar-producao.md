# 04 — Atualizar a produção

> **Status:** Em uso · **Última atualização:** 2026-07-23 · **Responsável:** devops-specialist
> **Pré-requisito:** a [instalação inicial](03-instalacao-inicial.md) já foi feita — este runbook é para **releases seguintes**
> **ADRs:** [0014](../27-ADR/ADR-0014-fila-database.md) (fila por cron) · [0016](../27-ADR/ADR-0016-hospedagem.md) (hospedagem)

## 1. Objetivo

Publicar uma versão nova em `gestao.donaarteira.com.br` sem derrubar a operação e sem esquecer nenhum passo.

O pipeline automatizado descrito no [README da pasta](README.md#3-pipeline-github-actions) — deploy por Deployer, staging automático, health check — **ainda não existe**. Hoje o deploy é manual, por SSH. Este documento é o que se executa de fato até lá; a distância entre os dois é dívida registrada, não descuido.

## 2. O que é fácil esquecer

| Passo | Consequência de pular |
|---|---|
| `npm run build` **local** + rsync | O servidor serve os assets da versão anterior. A tela quebra de formas confusas, porque o HTML é novo e o JS é velho |
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
  | gzip > ~/backups/erp-$(date +%Y%m%d-%H%M%S).sql.gz
```

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

```bash
rsync -avz public/build/ \
  usuario@host:~/domains/donaarteira.com.br/erp/gestao-app/public/build/
```

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
| 5 | Agendador vivo | `tail -3 ~/schedule.log` cresce |
| 6 | Logs graváveis | `storage/logs/laravel.log` |
| 7 | WordPress intacto | `https://donaarteira.com.br` |

## 4. Rollback

```bash
cd $BASE/erp
git log --oneline -5          # achar o commit anterior
git checkout <commit-anterior>
cd $APP && composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache
```

E reenviar os assets daquela versão pelo rsync (reconstruí-los localmente a partir do mesmo commit).

**Migration não volta sozinha.** `migrate:rollback` desfaz o último *batch* — o CI verifica que os `down()` funcionam ([regra 2 da pasta 04](../04-Banco-de-Dados/02-convencoes-de-banco.md#migrations-regras-para-o-gate-01)), mas migration destrutiva exige restaurar o dump da §3.2. A regra de expand/contract existe justamente para tornar isso raro.

## 5. Primeira entrada num ambiente novo

O `AdminInicialSeeder` cria a conta admin na primeira execução e **imprime a senha provisória uma única vez no console** — a menos que `ADMIN_INICIAL_SENHA` esteja definida no `.env`. Anotar na hora: ela não vai para log nenhum. A troca é obrigatória no primeiro acesso.

Reexecutar o seeder num banco que já tem admin **não** redefine senha alguma.

## 6. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Esquecer o rsync dos assets | **Alta** | Alto | §2 abre com isso; o item 2 da §3.6 verifica pelo hash |
| Deploy sem `db:seed` deixar permissão nova sem existir | Média | Alto | §3.5 e a explicação do porquê |
| `git pull` sobrescrever hotfix manual do servidor | Baixa | Alto | Nota da §3.3; hotfix manual deve virar commit no mesmo dia |
| Deploy manual divergir do que o CI testou | Média | Alto | Automatizar o deploy (dívida do README §3); até lá, publicar só o que está em `main` com CI verde |
