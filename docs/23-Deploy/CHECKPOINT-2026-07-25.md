# Checkpoint — 2026-07-25

> Ponto de retomada do trabalho. Não é documento canônico de arquitetura — é um "onde paramos e o que fazer a seguir". Substitui o checkpoint de 2026-07-24. Apagar quando o Gate 01 tiver seu próprio acompanhamento.

## Onde chegamos

Sessão longa e de entrega. O **módulo Identity fechou** (2FA e convite,
suas duas últimas dívidas) e o **módulo Catálogo nasceu inteiro** —
documentação, ADR e código. Tudo publicado e verificado em produção.

O que mais rendeu, porém, não foi o que estava planejado: **três achados
de segurança e um de infraestrutura**, todos encontrados por acaso, ao
usar o sistema em vez de olhar para o código.

---

## 1. Identity fechado

### 2FA TOTP ([ADR-0021](../27-ADR/ADR-0021-2fa-totp.md), aceito)

`laravel/fortify` usado **só pelas 4 Actions de 2FA** — nenhuma rota,
controller ou view do pacote roda.

**A ADR estava errada sobre como barrar o provider**, e isso só apareceu
na implementação: ela dizia "não registrar em `bootstrap/providers.php`".
Não basta — o Fortify se declara em `extra.laravel.providers` e o
**package discovery o registra sozinho**. O bloqueio real é
`extra.laravel.dont-discover` no nosso `composer.json`, e há teste de
arquitetura guardando, porque um `composer update` desfaz isso sem aviso.
O `laravel/passkeys` veio junto, como dependência obrigatória não
prevista, e foi barrado igual.

**Quem cifra as colunas de 2FA é o Fortify, não os casts do model.**
Manter os dois cifraria duas vezes — funcionaria por acidente, com um
dono a mais que o necessário.

**O login deixou de ser `Auth::attempt()`.** Com segundo fator, acertar a
senha é metade da credencial: o id fica num limbo de sessão de 5 minutos
e só vira sessão autenticada depois do TOTP. Efeito colateral bom —
`login.ok` na trilha passou a significar "entrou", não "acertou a senha",
e `last_login_at` parou de mentir sobre desafio abandonado no meio.

**Dispositivo lembrado por 30 dias** (pedido do dono): só depois de TOTP
genuíno, **nunca** por código de recuperação — que é justamente o caminho
de "perdi o celular". Par selector/verifier com hash no banco; os 30 dias
contam da confirmação, não do último uso, senão cookie roubado e usado
com frequência nunca venceria. Revogado ao trocar senha, desativar ou
reconfigurar o 2FA.

### Convite por e-mail — a dívida era maior que o registrado

O que estava anotado era "falta o listener que envia". Eram **três**
buracos:

1. **Um laço fechado.** Nada promovia `invited → active`. A pessoa
   definia a senha, tentava entrar, e o middleware a expulsava com
   *"Verifique o convite enviado por e-mail"* — mandando-a de volta ao
   e-mail que não resolvia nada. Só um admin destravava, mexendo no
   status à mão.
2. **A fila nunca rodou.** `QUEUE_CONNECTION=database` estava configurado
   desde sempre, mas nada agendava `queue:work`. Todo job enfileirado
   ficaria parado em silêncio.
3. **Um terceiro caminho de senha ainda com `Password::defaults()`** — o
   reset por e-mail, justamente por onde o convidado define a **primeira**
   senha.

---

## 2. Catálogo — do zero, incluindo a documentação

**O módulo não tinha pasta em `docs/`.** Produto era uma linha do modelo
conceitual e citações espalhadas por 08/09/10. Criada a
[pasta 32](../32-Catalogo/README.md) e a
[ADR-0022](../27-ADR/ADR-0022-modelo-de-produto-e-sku.md), fechando duas
perguntas que a própria documentação adiara "para o Gate 01, com dados
reais" — os dados chegaram no inventário da pasta 31.

- **Variação é produto próprio** (BR-009, nova). A cor é acabamento de
  pintura manual: 39 cores, produzidas separadamente, com tintas e custos
  diferentes. Saldo agregado de "budas" não responde à pergunta que a
  operação faz. De quebra, os 37 produtos de título duplicado do Woo
  deixam de ser sujeira e viram o que sempre foram.
- **SKU `DA-0001`**, sequencial e sem significado embutido. Nenhum dos
  716 produtos do Woo tem SKU, então o ERP **cria**. Sem prefixo por
  categoria porque a BR-002 exige imutabilidade, e código com
  classificação dentro mente quando a classificação muda.

Implementado com `products`, `product_categories`, `product_prices` e
`packages`; preço é histórico (linha nova, o model recusa update e
delete); listagem com filtro "sem preço de atacado".

---

## 3. Os quatro achados

### 3.1 🔴 O cadastro público estava aberto em produção

`/register` respondia **200**. Qualquer pessoa criava conta e, como
`users.status` tem default `active`, essa conta **entrava** no sistema.
Sem papel não alcançava nada (BR-801 segurou), mas ocupava a tabela de
usuários e aparecia na listagem como se alguém a tivesse convidado —
contradizendo a pasta 18, que diz que conta nasce por convite.

Era também o **quarto** caminho de senha com `Password::defaults()`.
Rota, controller, tela e teste removidos.

### 3.2 🔴 O painel não exigia 2FA

`/dashboard` tinha `conta.ativa` e `senha.trocada`, mas não
`2fa.confirmado` — a única tela autenticada sem ele. Um admin sem segundo
fator entrava, via o painel, e só era barrado ao tentar outra coisa. A
BR-804 valia em todo lugar **menos na porta de entrada**.

### 3.3 🟡 A política de senha tinha um terceiro caminho furado

Depois de unificar dois caminhos numa sessão anterior, o
`NewPasswordController` (reset por e-mail) continuava com
`Password::defaults()`. **Lição:** ao unificar uma regra, procurar
*todos* os caminhos, não os dois óbvios. Com o `/register` removido,
sobraram três, todos na `PasswordPolicy`.

### 3.4 🔴 `Schedule::command()` não funciona neste servidor (P-15)

O `Event` do scheduler dispara por `Symfony\Process` — **também em
foreground**, não só com `runInBackground()`. Com `proc_open` desabilitada,
o sintoma é traiçoeiro: o cron roda, o batimento é atualizado a cada
minuto, todos os sinais de saúde verdes, e a tarefa **simplesmente não
acontece**.

O único rastro era uma exceção no `laravel.log`. **Ou seja: só foi
diagnosticável porque o P-16 tinha sido corrigido no dia anterior.** A
correção do log pagou-se em menos de 48 horas.

Regra que fica: **toda tarefa agendada usa `Schedule::call()`** com
`Artisan::call()` dentro. A tabela da
[pasta 23/01 §7.5](01-validacao-ambiente-business.md) afirmava o
contrário e foi corrigida.

---

## 4. Duas premissas que caíram

### O desktop nunca foi alimentado

Não é um dump que faltou entregar: **o sistema nunca entrou em operação**.
O que existe no repositório é o código, útil como referência de regras
pretendidas, não como origem de dados.

- A migração tem **uma fonte só**, o WooCommerce. A pasta 17 dizia "duas
  fontes" e foi corrigida. Fica **mais simples**: sem dedupe cruzado, sem
  reconciliação de códigos, sem `stg_legacy_*`.
- RC-01 e RC-02 da pasta 31 perderam objeto.
- BR-002 e BR-003 citavam "legado" como origem. Era o **esquema** de um
  sistema que ninguém usou — distinção que muda o peso das duas regras.

### A empresa vende atacado, mas o preço nunca foi registrado

Confirmado pelo dono: os dois canais existem, então a BR-003 é regra
real. **Falta o dado, não a regra** — e a equipe preencherá produto a
produto, sem regra de formação a partir do varejo. Daí o filtro "sem
preço de atacado" na listagem: sem ele, seria varrer 716 fichas.

---

## 5. Estado da produção

Tudo publicado e verificado. As **nove** verificações do
[runbook 04](04-atualizar-producao.md) §3.6 passam.

| Item | Estado |
|---|---|
| Identity | ✅ completo — 2FA e convite no ar |
| Catálogo | ✅ tabelas criadas, vazias, prontas para a migração |
| SMTP | ✅ `smtp.hostinger.com:465`, envio real confirmado |
| Fila | ✅ worker por cron, `jobs: 0 \| failed: 0` |
| Backup | ✅ sem prompt de senha (`~/.my.cnf`), automatizável |
| `APP_NAME` | ✅ "Dona Arteira" — era `Laravel`, e é o emissor gravado no QR do 2FA |
| Cadastro público | ✅ fechado (404) |
| Interface | ✅ pt-BR |

**Gates:** 187 testes Pest (633 asserções), PHPStan nível 6, Pint, tsc,
ESLint, Prettier, Vitest, build.

---

## 6. Por onde continuar

### O próximo módulo é o **estoque com ledger** (pasta 09, ADR-0008)

É o que o Gate 01 ainda espera, junto com clientes e a migração. Depende
do catálogo, que já está de pé, e **precede o cutover**: é o ledger que
recebe o inventário físico da migração (RC-03 — não importar saldo do
Woo, que 708 de 716 produtos não controlam).

### Depois: migração do catálogo

Perguntado pelo dono nesta sessão e respondido: **é possível e é o
caminho planejado** (pasta 17 + ADR-0010 para a carga; pasta 16 para a
sincronização contínua). Pontos que valem lembrar ao retomar:

- A extração é pela **API REST**, não pelo banco do Woo — o ADR-0010
  descartou ler o esquema interno (`postmeta` EAV) explicitamente. O dump
  local serviu ao inventário da pasta 31, não é caminho de carga.
- **Falta credencial:** consumer key/secret da API do WooCommerce.
- **Falta a camada `Integrations`** (pasta 15) como código — hoje só
  existem `Identity` e `Catalog`.
- Vem do Woo: nome, descrição (higienizar HTML), categorias, preço de
  varejo, imagens, medidas (670/716 completos), e os 39 variáveis + 77
  variações viram ~793 produtos.
- **Não vem:** SKU (gerado, com aprovação humana), preço de atacado,
  estoque, composição dos 213 kits, NCM/CEST/origem.
- Exige decisão humana no saneamento: os 14 grupos de título duplicado, a
  aprovação dos SKUs, e a reclassificação de categorias (RC-06 — tema
  misturado com sazonal, material e merchandising).

### Pendências menores

- **Reescanear o 2FA** do admin, se quiser que o autenticador mostre
  "Dona Arteira" em vez de "Laravel" — o emissor é gravado no QR no ato
  do cadastro, então trocar o `APP_NAME` depois não atualiza quem já
  cadastrou.
- **Personalizar a tela de login** (logo, identidade visual) — puramente
  visual, sem consequência técnica.
- Perguntas de negócio ainda abertas na pasta 31 §98: composição dos kits
  (P-PROD-01), o que é revenda (P-PROD-03), NCM por material (P-FIS-03).
