# 02 — Como verificar o cron e o document root

> **Status:** Em revisão — **aguardando execução** · **Última atualização:** 2026-07-22 · **Responsável:** devops-specialist
> **ADRs relacionados:** [ADR-0014](../27-ADR/ADR-0014-fila-database.md) (fila por cron), [ADR-0016](../27-ADR/ADR-0016-hospedagem.md) (hospedagem)
> **Resolve:** itens **M-2** e **M-4** da [validação de ambiente](01-validacao-ambiente-business.md#5-verificações-manuais-o-script-não-alcança) · **Script:** [`cron-test.php`](cron-test.php)

## 1. Objetivo

Fechar as duas últimas verificações de ambiente antes do primeiro código. Ambas têm o mesmo princípio:

> **Não confie no que o painel informa. Meça o comportamento real.**

O painel pode oferecer "a cada minuto" e o servidor executar de 5 em 5. O painel pode aceitar um caminho de document root e mesmo assim deixar o código-fonte acessível pela web. As duas verificações abaixo testam o **efeito**, não a configuração.

## 2. Verificação A — o cron honra 1 minuto?

### Por que importa

Sem worker persistente, a fila do ERP roda por cron ([ADR-0014](../27-ADR/ADR-0014-fila-database.md)). Se o intervalo real for de 5 minutos, a sincronização com o WooCommerce passa do NFR de 2 minutos e o risco de oversell aumenta ([R9](../00-Visao-Geral/04-analise-critica-gate00.md)).

### Passo 1 — descobrir o binário correto do PHP

O servidor é CloudLinux, onde costuma haver mais de uma versão de PHP instalada. Via SSH:

```bash
which php
php -v
```

Anote o caminho completo. Se `which php` devolver algo genérico, o próprio formulário de cron do painel normalmente sugere o caminho correto — use o do painel.

### Passo 2 — instalar o teste

Envie [`cron-test.php`](cron-test.php) para a sua home (**fora** de `public_html`):

```bash
# a partir da sua máquina
scp cron-test.php u917402451@SEU_HOST:~/cron-test.php
```

### Passo 3 — criar o cron job

No hPanel, procure a seção de **Cron Jobs** (costuma ficar em *Avançado* / *Advanced*). Escolha a opção de execução **a cada minuto** — ou, se houver campo de expressão personalizada, use `* * * * *`.

Comando (ajuste o caminho do PHP conforme o passo 1):

```
/usr/bin/php /home/u917402451/cron-test.php
```

### Passo 4 — esperar e medir

Deixe rodar **pelo menos 15 minutos**. Depois, via SSH:

```bash
php ~/cron-test.php --relatorio
```

O script calcula os intervalos reais e emite o veredito sozinho.

### Passo 5 — limpar

Remova o cron job, o `cron-test.php` e o `cron-test.log`.

### Como ler o resultado

| Intervalo médio | Veredito | Ação |
|---|---|---|
| ~60 s | ✅ 1 minuto honrado | Nada a mudar. A fila roda como planejado |
| ~300 s | ⚠️ O plano só executa de 5 em 5 min | Aplicar uma das mitigações abaixo |
| > 330 s ou execuções faltando | 🔴 Grave | Gatilho de reabertura do [ADR-0016](../27-ADR/ADR-0016-hospedagem.md) |

### Se o cron não honrar 1 minuto

Três saídas, em ordem de preferência:

1. **Worker de vida longa dentro da janela do cron.** Em vez de processar e sair, o comando roda por quase todo o intervalo:
   ```
   */5 * * * * /usr/bin/php /home/.../artisan queue:work --stop-when-empty --max-time=280
   ```
   Dá cobertura quase contínua com um cron de 5 minutos. **Precisa ser testado**: o LVE do CloudLinux pode encerrar processos longos, e é justamente isso que o teste revelaria.
2. **Laço interno com `sleep`.** Um wrapper que executa o processamento cinco vezes, com pausa de 60 s entre elas, dentro de uma única execução do cron. Mais frágil que a opção 1.
3. **Aceitar a latência e registrar.** Ajustar o NFR de sincronização de 2 para 5 minutos, aumentar o buffer de estoque do site ([BR-204](../01-Regras-de-Negocio/01-registro-de-regras.md)) para compensar, e registrar a dívida no ADR-0016.

Nenhuma das três é impeditiva. O objetivo do teste é escolher com dados, não descobrir depois.

## 3. Verificação B — o document root aponta para `public/`?

### Por que importa

O Laravel espera que a web enxergue **apenas** a pasta `public/`. Se o document root apontar para a raiz da aplicação, ficam acessíveis pela internet: o `.env` (com senha do banco e credenciais de integração), o código-fonte e — no Gate 05 — o **certificado A1**. É a diferença entre um sistema e um vazamento.

### Passo 1 — organizar as pastas

O arranjo desejado mantém a aplicação **fora** da área pública:

```text
/home/u917402451/
├── gestao/                 ← aplicação Laravel (NÃO acessível pela web)
│   ├── app/  bootstrap/  config/  vendor/
│   ├── .env                ← senhas
│   ├── storage/            ← certificado A1 vai aqui (Gate 05)
│   └── public/             ← ESTA é a única pasta que a web pode ver
└── public_html/            ← site WordPress, permanece intocado
```

### Passo 2 — apontar o subdomínio

No hPanel, na seção de **Subdomínios**, crie (ou edite) `gestao`. Procure o campo de **pasta personalizada / custom folder** e aponte para:

```
/home/u917402451/gestao/public
```

Se o painel **não** aceitar um caminho fora de `public_html`, vá para a §3.5.

### Passo 3 — plantar os canários

Dois arquivos, via SSH:

```bash
# Canário 1: na RAIZ da aplicação — jamais pode ser acessível
echo "SE VOCE ESTA LENDO ISTO PELA WEB, O DOCUMENT ROOT ESTA ERRADO" \
  > ~/gestao/CANARIO-RAIZ.txt

# Canário 2: simula o .env — o alvo mais valioso para um atacante
echo "APP_KEY=canario-nao-e-uma-chave-real" > ~/gestao/.env

# Canário 3: dentro de public/ — este SIM deve ser acessível
echo "DOCUMENT ROOT CORRETO" > ~/gestao/public/canario-ok.txt
```

### Passo 4 — testar pela web

Abra as três URLs no navegador (ou use `curl -i`):

| URL | Resultado esperado | Se vier diferente |
|---|---|---|
| `https://gestao.donaarteira.com.br/canario-ok.txt` | ✅ mostra "DOCUMENT ROOT CORRETO" | O subdomínio não está apontando para `public/` |
| `https://gestao.donaarteira.com.br/CANARIO-RAIZ.txt` | ✅ **404** | 🔴 **REPROVADO** — a raiz da aplicação está exposta |
| `https://gestao.donaarteira.com.br/.env` | ✅ **404** | 🔴 **REPROVADO — crítico.** Pare tudo e corrija antes de qualquer deploy |

**Só há aprovação se o primeiro abrir e os outros dois derem 404.** Um 403 em vez de 404 é aceitável, mas 404 é preferível (não revela a existência do arquivo).

### Passo 5 — limpar

Apague os três canários.

### 3.5 Se o painel não permitir caminho fora de `public_html`

Em ordem de preferência:

1. **Subdomínio com pasta própria dentro de `public_html`, apontando para o `public` do app.** Ex.: aplicação em `~/gestao`, document root em `~/public_html/gestao-public`, cujo conteúdo é o `public/` do Laravel. Como o `symlink` está bloqueado neste plano, os arquivos precisam ser **copiados de fato** para lá, e o `public/index.php` precisa ser editado para apontar para a aplicação um nível acima. É funcional, mas o deploy fica com um passo extra a documentar no runbook.
2. **`.htaccess` reescrevendo tudo para `public/`.** Funciona, mas é a opção **mais arriscada**: se a regra falhar, quebrar numa migração de servidor ou for sobrescrita, o código-fonte inteiro fica exposto sem aviso. Se for o único caminho possível, **proteger em profundidade**: negar explicitamente `.env`, `storage/`, `vendor/` e `.git/` no próprio `.htaccess`, e repetir o teste dos canários após qualquer alteração.
3. **Abrir chamado no suporte da Hostinger** pedindo o ajuste do document root. É pedido comum para aplicações PHP com front controller.

> Independentemente do caminho escolhido, o **teste dos canários é o critério de aceite**. Sem os três resultados corretos, não há deploy.

## 4. Registro do resultado

| Verificação | Data | Resultado | Veredito |
|---|---|---|---|
| **M-2** — cron de 1 minuto | | intervalo médio: ___ s | ⏳ |
| **M-4** — document root | | canário-ok: ___ · canário-raiz: ___ · `.env`: ___ | ⏳ |

Ao concluir, atualizar a tabela de verificações manuais em [01-validacao-ambiente-business.md §5](01-validacao-ambiente-business.md#5-verificações-manuais-o-script-não-alcança).

## 5. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Concluir pelo painel, sem medir | **Alta** | Alto | Este documento existe para isso: o critério é o comportamento observado |
| Canários esquecidos no servidor | Média | Baixo | Passo 5 de cada verificação; o `.env` canário tem valor falso de propósito |
| Document root correto hoje, quebrado após migração de servidor | Baixa | **Crítico** | Repetir o teste dos canários após qualquer mudança de infraestrutura — item de runbook |
| Mitigação de cron longo ser morta pelo LVE | Média | Médio | Testar a opção 1 da §2 antes de adotá-la |
