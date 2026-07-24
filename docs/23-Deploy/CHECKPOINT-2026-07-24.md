# Checkpoint — 2026-07-24

> Ponto de retomada do trabalho. Não é documento canônico de arquitetura — é um "onde paramos e o que fazer a seguir". Substitui o checkpoint de 2026-07-23. Apagar quando o Gate 01 tiver seu próprio acompanhamento.

## Onde chegamos hoje

Sessão curta e de fechamento: o **P-16 foi corrigido** — o achado mais
grave em aberto — e a produção deixou de estar defasada. Nenhum código de
negócio novo; o que se fez foi tirar do caminho as três coisas que
impediam de confiar no que já existia: um CI vermelho, um log que não
logava e um deploy pendente.

### O CI estava vermelho e ninguém tinha visto

O último commit do dia 23 (`c8d96e2`) reprovou o job `tests` — três
testes de feature com `Inertia page component file does not exist`.

A causa: **`config/inertia.php` nunca fora publicado**, e o padrão do
pacote procura as páginas em `resources/js/Pages` — com P maiúsculo —
enquanto o starter kit do Laravel 12 e o resolvedor de `app.tsx` usam
`resources/js/pages`. O sistema de arquivos do Windows não diferencia
maiúsculas; o do Linux, sim. Por isso a falha **só existia no CI**: era
invisível na máquina onde o código é escrito.

Corrigido em `ae0172d`, com o teste que impede a reincidência:
`tests/Architecture/CaminhosSensiveisAMaiusculasTest.php`. Em vez de
perguntar ao sistema de arquivos se o caminho existe — pergunta que o
Windows responde errado —, ele **compara os nomes literalmente** contra o
que o `scandir` do diretório-pai devolve. Verificado removendo o config: o
teste reprova.

Efeito colateral necessário: `tests/Pest.php` passou a estender o
`TestCase` também em `Architecture`, porque `config()` e
`resource_path()` precisam do container. Sem `RefreshDatabase` — nenhum
desses testes toca o banco.

### ✅ P-16 resolvido — mas não pelo caminho documentado

O runbook mandava desmarcar `psr` em hPanel → PHP Configuration →
Extensions. **Isso foi tentado duas vezes e não funciona.** Depois de
desmarcar, `extension=psr.so` continuava na linha 193 do `alt_php.ini` e
o `mtime` do arquivo nem mudava. O painel aceita a ação e não a aplica.

O que resolveu foi editar o ini **direto por SSH**. A descoberta que
destravou: apesar de morar em `/opt`, o arquivo é **do usuário** e
gravável por ele (`dono=u917402451`, `-rw-r--r--`).

Verificado nos dois SAPIs, o que a sessão anterior não conseguira fazer:

| SAPI | Como | Resultado |
|---|---|---|
| CLI | `php -r 'var_dump(extension_loaded("psr"));'` | `bool(false)` |
| CLI | `Log::error("canario")` + `tail laravel.log` | canário gravado |
| **LiteSpeed** | sonda em `public/` instanciando `Monolog\Logger` | **200** · `psr_loaded=false` · `monolog=ok` |

A sonda é exatamente o arquivo que devolvia **500** antes. Foi apagada
depois de verificar.

O `laravel.log` da produção recebeu hoje **a primeira linha de sua vida**
— antes disso nunca fora escrito, porque nunca conseguiu ser.

> ⚠️ **A correção não é definitiva.** O painel reescreve o `alt_php.ini`
> quando se mexe em configuração de PHP (visto em 23/07 às 13:45 e às
> 14:29). Uma ação futura no hPanel pode trazer o `extension=psr.so` de
> volta sem aviso. Por isso o item 6 da §3.6 do runbook virou verificação
> **de todo deploy**, não conferência de uma vez só.

### Produção atualizada — `e3c6a36` → `4eb9076`

O que estava construído desde 23/07 e não tinha sido publicado agora está
no ar: as telas de gestão de contas, os dois middlewares (`conta.ativa` e
`senha.trocada`), Policy, Services e eventos do Identity.

As sete verificações da §3.6, todas feitas:

| # | Verificação | Resultado |
|---|---|---|
| 1 | Aplicação abre | 200 |
| 2 | Assets na versão certa | `app-Iknvrp32.js` — o mesmo hash do build local |
| 3 | Sem stack trace em rota inexistente | 404, 5992 bytes, sem `vendor/laravel` nem `APP_KEY` |
| 4 | Migrations aplicadas | 6/6 — **nenhuma nova neste release** |
| 5 | Agendador vivo | `~/scheduler-ultima-execucao.txt` = 16:40:03, 17 s antes da conferência |
| 6 | Logs graváveis | canário gravado (era o P-16) |
| 7 | WordPress intacto | 200 |

Rotas novas conferidas por HTTP — todas em pt-BR, ao contrário dos
caminhos de arquivo, que são em inglês: `/usuarios`, `/usuarios/novo`,
`/trocar-senha` respondem **302 → /login** para anônimo.

Depois de todo esse tráfego, o `laravel.log` tem **2 linhas** — os dois
canários. Nenhum erro.

**P-15 apareceu de novo**, como previsto: `composer install` quebrou no
`package:discover` por `proc_open` desabilitada. O contorno do runbook
(rodar `php artisan package:discover` direto) resolveu.

### Credencial versionada — removida

O arquivo de rascunho `.db` na raiz estava **rastreado** e continha nomes
de banco e a senha do staging em texto puro. Entrou no commit `e13ae02`.
Saiu do rastreamento e entrou no `.gitignore` (`4eb9076`); o arquivo
continua no disco.

Reescrever o histórico foi avaliado e **descartado**: o repositório é
privado e o `push --force` no `main` é mais invasivo que o ganho. A
consequência de não reescrever é que **a senha do staging precisa ser
rotacionada** — está pendente.

## PRÓXIMO PASSO (retomar exatamente aqui)

**1. Publicar em produção** pelo [runbook 04](04-atualizar-producao.md).
Há **migration nova** (`security_events`) — o primeiro deploy desta
sessão não tinha. O backup da §3.2 deixa de ser formalidade.

**2. Fechar as duas dívidas do Identity que sobraram:**
   - **Fluxo de 2FA TOTP** (BR-804): as colunas existem, o fluxo não. A listagem já exibe a pendência por conta. Exige escolher pacote — logo, **ADR** antes do código. O enum `SecurityEventType` já reserva `two_factor.enabled` e `two_factor.disabled`, que nada emite ainda.
   - **Convite por e-mail** (pasta 18 §3): o estado `invited` e o evento `UserInvited` existem, e a trilha já registra o convite. Falta o listener que envia — e falta SMTP configurado em produção, que é o pré-requisito real.

**2. Depois do Identity: módulo Catalog** — produtos, SKU (BR-002),
preços varejo/atacado (BR-003), embalagens (BR-004). É o que o Estoque e
as Vendas precisam existir antes.

## Fechado nesta sessão, depois do deploy

- ✅ **Senha do staging rotacionada** pelo dono. Era a pendência aberta
  pelo `.db` versionado.
- ✅ **Matriz de permissões sem célula pendente.** As seis marcadas 🆕
  foram confirmadas pelo dono — nenhuma recusada. O enum `Role` já as
  concedia, então não houve mudança de código; o que mudou foi a
  [pasta 19 §3.1](../19-Permissoes/README.md), que deixou de chamá-las de
  inferência e passou a registrar **por que** cada acesso foi ampliado —
  a pergunta que reaparece na revisão trimestral da pasta 25.
- ✅ `.claude/settings.json` versionado: `ssh gestao-prod` e `scp` são
  ferramentas do projeto enquanto o deploy for manual, não preferência de
  máquina.
- ✅ **Trilha de segurança `security_events`** (pasta 26 §2.1) e
  **`last_login_at`** — duas das quatro dívidas do Identity. Detalhe
  abaixo.

## A trilha de segurança, e o que ela revelou

Tabela própria, não a `audits`: o laravel-auditing registra o diff de um
*model*, e os fatos que mais importam não têm model — um login que falhou
com e-mail inexistente não altera linha alguma, e é exatamente o que se
quer ver quando alguém tenta entrar à força. Papel vive em tabela pivô,
então ganhar `finance.manage` também não aparecia.

**Um desvio deliberado da pasta 26 §3:** registrar não pode negar
serviço. A gravação é síncrona mas dentro de `try/catch`, com o erro indo
para `Log::critical`. Se a trilha falhasse dentro do login, uma tabela
cheia trancaria todo mundo para fora — o mesmo formato do P-16, o
mecanismo de vigilância derrubando a operação que existe para proteger. E
isso só é honesto porque o log da produção passou a funcionar hoje.

### Três coisas que apareceram no caminho

1. **A política de senha valia pela metade.** A troca obrigatória exigia
   12 caracteres com verificação contra vazamentos; a tela de
   configurações do starter kit aceitava `Password::defaults()` — 8, sem
   checagem. Quem trocasse a senha por lá escapava da regra que a pasta 18
   afirma, e nada reprovava. A regra passou a viver em `PasswordPolicy`,
   com teste nos dois caminhos.
2. **Autoexclusão de conta removida.** Não por revisão: a FK `RESTRICT`
   da trilha reprovou o teste do starter kit ao tentar apagar um usuário
   com eventos. O ciclo de vida da pasta 18 não tem estado "excluído".
   Confirmado pelo dono.
3. **Um teste verde localmente e vermelho no CI, de novo** — e desta vez
   não por caixa de caminho. O verificador do Laravel trata falha de
   requisição ao HaveIBeenPwned como "senha não vazada": aqui a chamada
   morria em silêncio e o teste passava, no CI ela funcionava e reprovava.
   O `Tests\TestCase` passou a falsificar a API para a suíte inteira, com
   `preventStrayRequests` para que nenhuma chamada externa escape.

### Duas armadilhas do framework, para não custarem tempo de novo

- **`AuthorizationException` nunca chega aos callbacks de render.** O
  `prepareException` do handler roda **antes** e a converte em
  `AccessDeniedHttpException`. Um callback tipado com a original nunca
  casa — e falha em silêncio, sem erro algum. A original fica em
  `getPrevious()`.
- **Tipo de união no primeiro parâmetro do callback não funciona.** O
  Laravel descobre a qual exceção o callback pertence com
  `Reflector::getParameterClassName`, que devolve `null` para `A|B`. O
  callback fica registrado para classe nenhuma. Mesmo silêncio.

## Pendências abertas

- **`proc_open` desabilitada** (P-15). O contorno funciona e está no
  runbook. Agora se sabe que o `alt_php.ini` é gravável por SSH — o mesmo
  caminho que resolveu o P-16 **pode** resolver este, mas reabilitar
  `proc_open` é decisão de segurança, não de conveniência: não foi feita.
- **Dívida do `npm audit`** — 13 vulnerabilidades, só o `axios` 1.7.9
  chega ao bundle (SSRF por URL absoluta, DoS por falta de checagem de
  tamanho). Corrigir exige escolha: `npm audit fix` mexe em 59 pacotes, ou
  subir `@inertiajs/react` de 2.0.3 para 3.6.1, que é *major*. Merece
  ciclo próprio, não carona num release. O que **não** chega ao bundle,
  verificado e não presumido: `form-data` e `follow-redirects` (adaptador
  Node do axios, removido pelo tree-shaking — zero ocorrências de
  `CombinedStream`, `_boundary` e `getLengthSync` no bundle) e
  `shell-quote` e `lodash` (vêm do `concurrently`, usado só pelo script
  `composer dev`).
- **ADR-0018** (cobrança boleto/PIX) — ainda `Proposto`, aguarda o dono e
  a resposta do cliente sobre o banco.
- **Externas de lead longo:** pauta fiscal ao contador
  (`docs/13-Fiscal/01`), convênio bancário, chaves REST do Woo, dump do
  banco `dona_arteira` do desktop.
- **M-5** (backup automatizado), **M-8** (certificado A1 fora do
  webroot), **CA bundle** (`curl.cainfo`, antes do Gate 05).
- **Deploy automatizado** — hoje é manual por SSH. A distância para o
  pipeline descrito no README da pasta é dívida registrada.

## Armadilhas deste ambiente

P-13 cron usa caminho absoluto · P-14 interpretador explícito + arquivo
644, nunca 777 · P-15 trocar versão de PHP troca `disable_functions`
(revalidar sempre) · P-16 extensão `psr` quebra o Monolog — **corrigida,
mas o painel pode reverter** · symlink: `ln -s` do shell funciona,
`symlink()` do PHP não · hPanel gerencia cron por fora (`crontab` nem
existe no host) · hPanel não honra o redirecionamento escrito no comando
do cron · comando longo no painel quebra (usar wrapper) · PHPStan estoura
os 128 M do PHP CLI — usar `composer analyse`.

**Quatro novas, aprendidas hoje:**

- **O hPanel aceita configuração de PHP e não aplica.** Desmarcar `psr`
  não mexeu no `alt_php.ini`. Conferir sempre o **efeito no servidor**,
  nunca a tela do painel.
- **`mysqldump | gzip` não falha.** Se o dump não sai, o gzip grava mesmo
  assim e o arquivo fica com **20 bytes** — um gzip vazio com cara de
  backup. Aconteceu duas vezes hoje. Sempre conferir o tamanho; o comando
  da §3.2 do runbook já termina com `&& ls -la`.
- **Windows esconde erro de caixa em caminho.** Vale para qualquer
  caminho escrito em string, não só o do Inertia — o teste de arquitetura
  criado hoje cobre só as páginas.
- **Teste que faz chamada externa mente sobre o próprio resultado.** O
  `uncompromised()` da validação de senha consulta o HaveIBeenPwned, e o
  Laravel trata falha de requisição como "não vazada" — então a suíte
  ficava verde justamente quando a verificação não acontecia. Falsificar
  a chamada é o mínimo; `Http::preventStrayRequests()` no `TestCase` é o
  que impede a próxima.
