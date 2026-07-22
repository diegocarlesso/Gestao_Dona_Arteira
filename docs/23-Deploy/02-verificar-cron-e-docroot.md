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

No hPanel, seção **Cron Jobs** (costuma ficar em *Avançado*). Escolha execução **a cada minuto**, ou use a expressão `* * * * *`:

```
/usr/bin/php /home/u917402451/cron-test.php
```

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

#### Arranjo A (recomendado) — aplicação irmã do `public_html`, não dentro dele

```text
/home/u917402451/domains/donaarteira.com.br/
├── public_html/            ← WordPress (docroot de donaarteira.com.br) — intocado
└── gestao-app/             ← aplicação Laravel — NENHUM vhost alcança
    ├── .env  app/  vendor/  storage/
    └── public/             ← docroot de gestao.donaarteira.com.br
```

Document root a informar no painel para o subdomínio:

```
/home/u917402451/domains/donaarteira.com.br/gestao-app/public
```

Por que este arranjo é o certo: `gestao-app/` é **irmã** de `public_html/`, não filha. Nenhuma URL do domínio principal consegue alcançá-la, porque ela está fora da árvore que o vhost do WordPress serve. A proteção é estrutural, não depende de `.htaccess` nem de configuração que possa ser sobrescrita.

**Verificar no hPanel:** o campo de pasta personalizada do subdomínio aceita esse caminho? É a única incógnita restante. Se aceitar, o arranjo B abaixo torna-se desnecessário.

#### Arranjo B — aplicação dentro de `public_html`, com bloqueio explícito

Se o painel só aceitar caminhos sob `public_html`:

```text
public_html/
├── (WordPress)
└── gestao/
    ├── .htaccess           ← BLOQUEIO — ver abaixo
    ├── .env  app/  vendor/  storage/
    └── public/             ← docroot do subdomínio
```

O `public_html/gestao/.htaccess` nega todo acesso àquele diretório:

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
