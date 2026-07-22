# 02 — Como verificar o cron e o document root

> **Status:** Em revisão — **aguardando execução** · **Última atualização:** 2026-07-22 · **Responsável:** devops-specialist
> **ADRs relacionados:** [ADR-0014](../27-ADR/ADR-0014-fila-database.md) (fila por cron), [ADR-0016](../27-ADR/ADR-0016-hospedagem.md) (hospedagem)
> **Resolve:** itens **M-2** e **M-4** da [validação de ambiente](01-validacao-ambiente-business.md#5-verificações-manuais-o-script-não-alcança)
> **Scripts:** [`cron-test.php`](cron-test.php) · [`mapear-estrutura.sh`](mapear-estrutura.sh)

## 1. Objetivo

Fechar as duas últimas verificações de ambiente antes do primeiro código. Ambas seguem o mesmo princípio:

> **Não confie no que o painel informa. Meça o comportamento real.**

O painel pode oferecer "a cada minuto" e o servidor executar de 5 em 5. O document root pode estar aparentemente correto e ainda assim expor o `.env` — por um caminho que ninguém pensou em testar.

## 2. Verificação A — o cron honra 1 minuto?

### Por que importa

Sem worker persistente, a fila do ERP roda por cron ([ADR-0014](../27-ADR/ADR-0014-fila-database.md)). Se o intervalo real for de 5 minutos, a sincronização com o WooCommerce passa do NFR de 2 minutos e o risco de oversell aumenta ([R9](../00-Visao-Geral/04-analise-critica-gate00.md)).

### Passo 1 — descobrir o binário correto do PHP

O servidor é CloudLinux, onde costuma haver mais de uma versão de PHP instalada. Via SSH:

```bash
which php && php -v
```

O script [`mapear-estrutura.sh`](mapear-estrutura.sh) também reporta isso, incluindo as alternativas em `/opt/alt/php*`. Se houver divergência, use o caminho que o próprio formulário de cron do painel sugerir.

### Passo 2 — instalar o teste

Envie [`cron-test.php`](cron-test.php) para um lugar **não servido pela web** (ver §3 — provavelmente a raiz da home).

### Passo 3 — criar o cron job

No hPanel: **Advanced → Cron Jobs**. Escolha execução a cada minuto (`* * * * *`).

> ⚠️ **Use caminho absoluto — sempre.** O cron executa com o diretório de trabalho na **raiz da home** (`/home/u917402451`), não no docroot. Um caminho relativo como `public_html/cron-test.php` é resolvido como `/home/u917402451/public_html/...` — e naquele servidor **essa pasta está vazia** (o docroot real é `~/domains/donaarteira.com.br/public_html`). O resultado é um erro silencioso:
>
> ```
> timeout: failed to run command 'public_html/cron-test.php': No such file or directory
> ```
>
> Esta armadilha vale para **todo** cron do projeto, incluindo o `schedule:run` do Laravel em produção.

> ⚠️ **Sempre invoque o interpretador explicitamente.** Se o comando contiver apenas o caminho do `.php`, o cron tenta **executar o arquivo como programa** — e um arquivo iniciado por `<?php`, sem shebang, não é executável. O erro é enganoso:
>
> ```
> timeout: failed to run command '/home/u917402451/cron-test.php': Permission denied
> ```
>
> "Permission denied" aqui **não** significa falta de permissão no arquivo. Tanto que dar `chmod 777` não resolve — e piora: ambientes com suexec/CageFS, como este servidor, **recusam executar arquivos graváveis por todos**. O `777` passa de inútil a causa adicional do problema.

Use a opção **Custom** e informe o comando completo, com o binário do PHP:

```
/usr/bin/php /home/u917402451/cron-test.php
```

Ou, para fixar a versão 8.4:

```
/opt/alt/php84/usr/bin/php /home/u917402451/cron-test.php
```

Permissão correta do arquivo: `chmod 644` — ele é **lido** pelo PHP, nunca executado.

Diagnóstico: o botão **View Output** de cada job mostra a saída e os erros da última execução — é o primeiro lugar a olhar quando um cron parece não rodar. Para guardar histórico, redirecione: `>> /home/u917402451/cron-debug.log 2>&1`.

> **O mesmo vale para produção.** O cron do agendador do Laravel seguirá exatamente este formato:
>
> ```
> * * * * * /usr/bin/php /home/u917402451/domains/donaarteira.com.br/gestao-app/artisan schedule:run >> /dev/null 2>&1
> ```
>
> Interpretador explícito, caminhos absolutos, arquivo não executável.

### Passo 4 — esperar e medir

Deixe rodar **pelo menos 15 minutos**. Depois:

```bash
php ~/cron-test.php --relatorio
```

O script calcula os intervalos reais e emite o veredito sozinho.

### Passo 5 — limpar

Remova o cron job, o `cron-test.php` e o `cron-test.log`.

### Como ler o resultado

| Intervalo médio | Veredito | Ação |
|---|---|---|
| ~60 s | ✅ 1 minuto honrado | Nada a mudar |
| ~300 s | ⚠️ Só de 5 em 5 min | Aplicar uma das mitigações abaixo |
| > 330 s ou execuções faltando | 🔴 Grave | Gatilho de reabertura do [ADR-0016](../27-ADR/ADR-0016-hospedagem.md) |

### Se o cron não honrar 1 minuto

Três saídas, em ordem de preferência — **nenhuma impeditiva**:

1. **Worker de vida longa dentro da janela do cron:**
   ```
   */5 * * * * /usr/bin/php /home/.../artisan queue:work --stop-when-empty --max-time=280
   ```
   Cobertura quase contínua com cron de 5 minutos. **Precisa ser testado**: o LVE do CloudLinux pode encerrar processos longos.
2. **Laço interno com `sleep`** — o comando processa cinco vezes com pausa de 60 s dentro de uma execução. Mais frágil.
3. **Aceitar a latência**: ajustar o NFR de 2 para 5 minutos, aumentar o buffer de estoque do site ([BR-204](../01-Regras-de-Negocio/01-registro-de-regras.md)) e registrar a dívida no ADR-0016.

## 3. Verificação B — o código-fonte está inacessível pela web?

> ⚠️ **Esta seção foi reescrita em 2026-07-22**, ao descobrir-se que o subdomínio `gestao.donaarteira.com.br` aponta para `public_html/gestao` — ou seja, **dentro** do document root do site principal. Isso cria um caminho de exposição que a versão anterior deste documento não testava.

### 3.1 O problema real

Com a aplicação dentro de `public_html`, existem **dois** vhosts capazes de ler aqueles arquivos:

```
gestao.donaarteira.com.br  → docroot: public_html/gestao/public   (o que queremos)
donaarteira.com.br         → docroot: public_html                  (o WordPress)
```

Mesmo apontando o subdomínio corretamente para `gestao/public`, o **domínio principal** continua enxergando a árvore inteira:

| URL | Resultado |
|---|---|
| `https://gestao.donaarteira.com.br/.env` | 404 ✅ — está acima do docroot do subdomínio |
| `https://donaarteira.com.br/gestao/.env` | 🔴 **serve o arquivo** |

O `.htaccess` do WordPress **não protege**: sua regra padrão só encaminha ao `index.php` o que **não existe** em disco (`RewriteCond %{REQUEST_FILENAME} !-f`). Um arquivo que existe é entregue direto pelo servidor.

O que estaria exposto: `.env` (senha do banco, credenciais do Woo, chaves de integração), todo o código-fonte, e — a partir do Gate 05 — o **certificado A1** em `storage/`.

### 3.2 Passo 1 — mapear a estrutura real

Antes de decidir onde a aplicação vai morar, rode via SSH:

```bash
bash mapear-estrutura.sh
```

O script mostra: se `public_html` é link simbólico, se existe uma estrutura `~/domains/`, o que há na raiz da home, e se o Composer está disponível.

### 3.3 Passo 2 — escolher o arranjo

Em ordem de preferência:

#### Estrutura real do servidor (mapeada em 2026-07-22)

```text
/home/u917402451/
├── public_html/                              ← VAZIO (stub herdado, não é o docroot)
├── domains/
│   └── donaarteira.com.br/
│       └── public_html/                      ← DOCUMENT ROOT REAL do WordPress
│           └── gestao/                       ← pasta atual do subdomínio ⚠️
└── DO_NOT_UPLOAD_HERE                        ← a home não é servida pela web
```

Duas correções importantes ao que se supunha antes:

- **`~/public_html` está vazio** — não é o document root. O site vive em `~/domains/donaarteira.com.br/public_html`.
- Consequentemente, a pasta `gestao` do subdomínio está **dentro** do docroot do WordPress, confirmando o risco da §3.1.

#### Restrição do painel (constatada em 2026-07-22)

O formulário de subdomínio do hPanel **força o prefixo `/public_html/`** no campo de pasta personalizada. Não é possível apontar o document root para fora de `public_html`. Isso elimina o arranjo ideal na sua forma direta — mas não obriga a aceitar a aplicação dentro do docroot do WordPress.

#### Arranjo A (recomendado) — aplicação fora, alcançada por link simbólico

```text
/home/u917402451/domains/donaarteira.com.br/
├── public_html/                 ← docroot do WordPress
│   └── gestao  ─────────────┐   ← LINK SIMBÓLICO (o painel aponta para cá)
└── gestao-app/               │  ← aplicação Laravel, FORA do docroot
    ├── .env app/ vendor/ storage/
    └── public/ ◄─────────────┘   ← alvo do link
```

O painel aponta o subdomínio para `/public_html/gestao`; esse caminho é um link que resolve para `gestao-app/public`. **Só a pasta `public/` fica alcançável.** Todo o resto da aplicação — `.env`, `vendor/`, `storage/` com o certificado A1 — permanece fora da árvore servida por qualquer vhost. A proteção continua sendo estrutural.

> **A distinção que viabiliza isto:** a validação de ambiente reportou `symlink` como BLOQUEADA, mas aquilo é a **função `symlink()` do PHP** (via `disable_functions`). O comando **`ln -s` do shell** é outra coisa e costuma funcionar normalmente. Precisa ser confirmado — ver teste abaixo.

##### Teste de viabilidade (2 minutos, via SSH)

```bash
BASE=~/domains/donaarteira.com.br
mkdir -p $BASE/teste-alvo
echo "LINK SIMBOLICO FUNCIONA" > $BASE/teste-alvo/ok.txt
ln -s ../teste-alvo $BASE/public_html/teste-link && echo "ln -s: OK" || echo "ln -s: BLOQUEADO"
cat $BASE/public_html/teste-link/ok.txt
```

Depois, pela web — isto testa se o servidor **segue** o link (`FollowSymLinks`):

```
https://donaarteira.com.br/teste-link/ok.txt
```

| Resultado | Conclusão |
|---|---|
| `ln -s: OK` **e** a URL mostra o texto | ✅ Arranjo A viável — é o caminho a seguir |
| `ln -s: OK` mas a URL dá 403/404 | `FollowSymLinks` desabilitado → ir para o Arranjo B |
| `ln -s: BLOQUEADO` | → Arranjo B |

Limpar depois: `rm $BASE/public_html/teste-link && rm -rf $BASE/teste-alvo`

> ### ✅ Resultado (2026-07-22)
>
> ```
> $ ln -s ../teste-alvo $BASE/public_html/teste-link && echo "ln -s: OK"
> ln -s: OK
> $ cat $BASE/public_html/teste-link/ok.txt
> LINK SIMBOLICO FUNCIONA
> ```
>
> E pela web, `https://donaarteira.com.br/teste-link/ok.txt` exibiu o conteúdo — **o servidor segue links simbólicos** (`FollowSymLinks` ativo).
>
> **Arranjo A adotado.** O Arranjo B fica registrado apenas como histórico da análise.

##### Se viável, o arranjo final

```bash
BASE=~/domains/donaarteira.com.br
# aplicação fica em $BASE/gestao-app (criada pelo composer create-project)
rm -rf $BASE/public_html/gestao          # remove a pasta criada pelo painel
ln -s ../gestao-app/public $BASE/public_html/gestao
```

Efeito colateral aceitável: `https://donaarteira.com.br/gestao/` também serve a aplicação, por seguir o mesmo link. Não é falha de segurança (é a pasta `public/`, feita para ser pública), mas convém redirecionar para o subdomínio por questão de higiene de URL.

> **Consequência para o deploy:** se `ln -s` funciona no shell, o **deploy atômico por troca de link volta a ser possível**, ao contrário do que a validação sugeria. O que continua indisponível é o `artisan storage:link`, que usa a função do PHP — o link de storage terá de ser criado à mão uma vez, via SSH.

#### Arranjo B — aplicação dentro de `public_html`, com bloqueio explícito

Usar apenas se o link simbólico não for viável.

**Antes de tudo, testar se o campo do painel aceita subpasta.** No formulário de subdomínio, digitar `gestao-app/public` no campo (após o prefixo fixo `/public_html/`). Se aceitar, o document root fica em `public_html/gestao-app/public` e a aplicação em `public_html/gestao-app/`:

```text
domains/donaarteira.com.br/public_html/
├── (WordPress)
└── gestao-app/
    ├── .htaccess           ← BLOQUEIO — obrigatório, ver abaixo
    ├── .env  app/  vendor/  storage/
    └── public/             ← docroot do subdomínio
```

Se o campo **não** aceitar barra, o document root do subdomínio será a própria raiz da aplicação — situação **inaceitável**, porque expõe `.env` e código-fonte até pelo próprio subdomínio. Nesse caso, ir direto ao Arranjo C.

O `gestao-app/.htaccess` nega todo acesso àquele diretório pelo domínio principal:

```apache
# Bloqueia o acesso ao diretório da aplicação pelo domínio principal.
# O subdomínio não é afetado: seu document root é gestao/public, e o
# servidor só lê .htaccess a partir do próprio document root para baixo.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
```

**Isto precisa ser comprovado com os canários da §3.4, não presumido.** O comportamento de `.htaccess` sob LiteSpeed é compatível com Apache, mas a única evidência aceitável é o teste.

Defesa em profundidade recomendada mesmo no arranjo B: um segundo `.htaccess` negando explicitamente `.env`, `storage/`, `vendor/` e `.git/`.

#### Arranjo C — último recurso

Abrir chamado no suporte da Hostinger pedindo o ajuste do document root do subdomínio. É um pedido comum para aplicações PHP com front controller.

### 3.4 Passo 3 — o teste dos canários

Plante os arquivos via SSH (ajuste o caminho conforme o arranjo escolhido):

```bash
APP=~/gestao-app          # ou ~/public_html/gestao, no arranjo B

echo "SE VOCE LE ISTO PELA WEB, A RAIZ DA APP ESTA EXPOSTA" > $APP/CANARIO-RAIZ.txt
echo "APP_KEY=canario-nao-e-uma-chave-real"                 > $APP/.env
mkdir -p $APP/storage && echo "canario-certificado"         > $APP/storage/CANARIO-STORAGE.txt
echo "DOCUMENT ROOT CORRETO"                                > $APP/public/canario-ok.txt
```

Agora teste **as duas origens**. Esta é a parte que não pode ser pulada:

| # | URL | Esperado |
|---|---|---|
| 1 | `https://gestao.donaarteira.com.br/canario-ok.txt` | ✅ abre |
| 2 | `https://gestao.donaarteira.com.br/CANARIO-RAIZ.txt` | ✅ 404 |
| 3 | `https://gestao.donaarteira.com.br/.env` | ✅ 404 |
| 4 | **`https://donaarteira.com.br/gestao/CANARIO-RAIZ.txt`** | ✅ 404 |
| 5 | **`https://donaarteira.com.br/gestao/.env`** | ✅ **404 — o teste mais importante** |
| 6 | **`https://donaarteira.com.br/gestao/storage/CANARIO-STORAGE.txt`** | ✅ 404 |

Por linha de comando, de uma vez:

```bash
for u in \
  "https://gestao.donaarteira.com.br/canario-ok.txt" \
  "https://gestao.donaarteira.com.br/CANARIO-RAIZ.txt" \
  "https://gestao.donaarteira.com.br/.env" \
  "https://donaarteira.com.br/gestao/CANARIO-RAIZ.txt" \
  "https://donaarteira.com.br/gestao/.env" \
  "https://donaarteira.com.br/gestao/storage/CANARIO-STORAGE.txt" ; do
  printf "%-70s %s\n" "$u" "$(curl -s -o /dev/null -w '%{http_code}' "$u")"
done
```

**Aprovação exige: a linha 1 com 200 e as linhas 2 a 6 com 404 (ou 403).** Qualquer 200 nas linhas 2–6 reprova — não há deploy até corrigir.

### 3.5 Passo 4 — limpar

Apague os quatro canários após o teste.

## 4. Registro do resultado

| Verificação | Data | Resultado | Veredito |
|---|---|---|---|
| **M-2** — cron de 1 minuto | | intervalo médio: ___ s | ⏳ |
| **M-4** — estrutura escolhida | | arranjo: ___ (A / B / C) | ⏳ |
| **M-4** — canários (1 a 6) | | ___ / ___ / ___ / ___ / ___ / ___ | ⏳ |

Ao concluir, atualizar a tabela de verificações manuais em [01-validacao-ambiente-business.md §5](01-validacao-ambiente-business.md#5-verificações-manuais-o-script-não-alcança).

## 5. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| **Testar só pelo subdomínio e dar por seguro** | **Alta** | **Crítico** | Foi exatamente a falha da primeira versão deste documento. As linhas 4–6 dos canários existem por isso |
| Concluir pelo painel, sem medir | Alta | Alto | O critério é sempre o comportamento observado |
| Document root correto hoje, quebrado após migração de servidor ou mudança de plano | Baixa | **Crítico** | Repetir o teste dos canários após qualquer mudança de infraestrutura — item obrigatório de runbook |
| `.htaccess` do arranjo B ser sobrescrito por deploy ou por plugin do WordPress | Média | **Crítico** | Arranjo A é preferível justamente por não depender disso; no B, o `.htaccess` entra no versionamento e é reconferido a cada release |
| Canários esquecidos no servidor | Média | Baixo | §3.5; o `.env` canário tem valor falso de propósito |
| Mitigação de cron longo ser morta pelo LVE | Média | Médio | Testar a opção 1 da §2 antes de adotá-la |
