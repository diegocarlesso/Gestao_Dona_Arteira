# ADR-0021: Como implementar o 2FA TOTP (BR-804)

> **Status:** ⚠️ Proposto · **Data:** 2026-07-24 · **Decisores:** security-specialist, chief-architect — **aprovação de custo/dependência pelo dono**
> **Módulos afetados:** 18 (Usuários), 05 (Backend — pacote novo), 25 (Segurança), 26 (Auditoria)
> **Depende de:** [ADR-0005](ADR-0005-autenticacao-sanctum.md) (decidiu *que* 2FA TOTP é obrigatório; não decidiu *como*) · **Respeita:** [ADR-0019](ADR-0019-inertia-substitui-spa.md) (controllers Inertia próprios, sem scaffolding paralelo), [ADR-0015](ADR-0015-camadas-e-repositorios.md) (Model é o modelo de domínio)

## Contexto

**BR-804** ([docs/01-Regras-de-Negocio/01-registro-de-regras.md](../01-Regras-de-Negocio/01-registro-de-regras.md), linha 119) exige 2FA obrigatório para os papéis `admin` e `finance`. O [ADR-0005](ADR-0005-autenticacao-sanctum.md) registrou essa exigência na camada de política — *"2FA TOTP para admin/finance (BR-804)"* — e não avançou além disso: nunca decidiu como o TOTP é gerado, validado, confirmado, revogado ou auditado. Este ADR fecha essa lacuna antes de qualquer código ser escrito, por pedido explícito do dono.

**Já decidido, não se reabre aqui:**
- TOTP via aplicativo autenticador (não SMS, não e-mail) — [docs/18-Usuarios/README.md §3.5](../18-Usuarios/README.md) e ADR-0005.
- WebAuthn/passkeys é evolução de fase 7 ([docs/18-Usuarios §7](../18-Usuarios/README.md)), fora de escopo agora.
- [ADR-0019](ADR-0019-inertia-substitui-spa.md) substituiu o scaffolding padrão de autenticação por controllers Inertia próprios do módulo (`app/Modules/Identity/Http/Controllers/`). [docs/05-Backend/README.md §5](../05-Backend/README.md) já rejeita deliberadamente pacotes que recriem rotas/controllers/views de admin/auth ("um admin paralelo criaria duas fontes de verdade de regras") — qualquer solução para o 2FA precisa respeitar essa fronteira: nenhuma rota, controller ou view de terceiro pode virar uma segunda porta de entrada.

**Restrição explícita do dono para esta decisão:** a solução tem que ser **gratuita** — sem custo de licença, sem SaaS de terceiros cobrado por verificação ou por usuário. Serviços como Twilio Authy, Auth0 ou AWS Cognito MFA estão fora de cogitação independentemente de qualquer vantagem técnica que ofereçam; a escolha se restringe a bibliotecas open-source rodando dentro do próprio ERP.

**Condição posta pelo dono na aprovação desta ADR:** desconforto com desafio TOTP a cada login no mesmo dispositivo recorrente — pediu um mecanismo de "lembrar este dispositivo por 30 dias". Isso é **distinto** da ausência de carência já decidida acima para o *setup* inicial (que fica confirmada como está — sem prazo): uma coisa é adiar a *configuração* do 2FA (recusado, sem carência), outra é dispensar o *desafio recorrente* num dispositivo que já provou posse do segundo fator uma vez (aceito, com prazo de 30 dias). As duas seções abaixo — "Enforcement de setup" e "Dispositivo lembrado" — tratam de cada uma; não confundir.

**Estado do código hoje (fatos, não decisão):**
- A migration `database/migrations/2026_07_23_120500_extend_users_for_identity.php` já criou `two_factor_secret` (TEXT), `two_factor_recovery_codes` (TEXT) e `two_factor_confirmed_at` (TIMESTAMP) — os três nomes exatos que o trait `Laravel\Fortify\TwoFactorAuthenticatable` (e as Actions de 2FA do Fortify) esperam encontrar no model.
- `app/Modules/Identity/Models/User.php` já aplica `casts()` `encrypted`/`encrypted:array` a essas colunas, já as inclui em `$hidden` e em `$auditExclude` (com o comentário já registrado sobre por que um segredo TOTP nunca pode entrar no diff de auditoria).
- `app/Modules/Identity/Enums/SecurityEventType.php` já reserva `TwoFactorEnabled` (`two_factor.enabled`) e `TwoFactorDisabled` (`two_factor.disabled`) — nada no código emite esses eventos ainda.
- `UserController::paraTela()` já expõe `requires_two_factor` e `has_two_factor` no payload da listagem/edição de usuário (via `User::requiresTwoFactor()`/`hasTwoFactorEnabled()`) — a pendência já aparece na tela, mas não existe fluxo de setup, confirmação, desativação nem desafio de login.
- `composer.json` não tem `laravel/fortify`, `pragmarx/google2fa` nem qualquer pacote de 2FA hoje — a escolha do pacote parte do zero.
- Existe precedente de enforcement obrigatório e incondicional no próprio módulo: `ForcedPasswordController` + middleware `EnsurePasswordIsChanged` (alias `senha.trocada`) prende o usuário na tela de troca de senha até resolver a pendência, aplicado a todas as rotas autenticadas, sem exceção nem prazo.
- O login atual (`AuthenticatedSessionController::store()` → `LoginRequest::authenticate()`) faz `Auth::attempt()` com rate limiting próprio (5 tentativas via `RateLimiter`, chave e-mail+IP) e, se as credenciais forem válidas, regenera a sessão imediatamente — não existe hoje nenhum passo intermediário de segundo fator entre a senha e a sessão autenticada.

## Decisão

**Usaremos `laravel/fortify` (MIT) exclusivamente pelas suas Action classes de 2FA** — `EnableTwoFactorAuthentication`, `ConfirmTwoFactorAuthentication`, `GenerateNewRecoveryCodes`, `DisableTwoFactorAuthentication` —, nunca pelas suas rotas, controllers, views ou feature flags.

- `composer require laravel/fortify`. O `Laravel\Fortify\FortifyServiceProvider` **nunca é registrado** em `bootstrap/providers.php` — nada do HTTP do pacote entra em execução; não existe `config/fortify.php` ativo, nenhuma `Features::` habilitada. Isso não é um hack: é o mesmo padrão que o próprio Laravel Jetstream usa oficialmente (consome as Actions do Fortify por baixo de uma UI própria, nunca as rotas do Fortify).
- As quatro Actions são resolvidas pelo container e chamadas por um Service próprio do módulo Identity (padrão `criar-service`, já usado em todo o resto do módulo), a partir de um Controller Inertia próprio e rotas do próprio `Routes/web.php` do Identity — a mesma estrutura de `UserController`/`ForcedPasswordController` hoje.
- Essas Actions operam sobre os três nomes de coluna que a migration já criou — não é coincidência; é o motivo de terem sido nomeados assim quando a migration foi escrita.
- **QR code:** `laravel/fortify` já traz `bacon/bacon-qr-code` (**BSD-2-Clause**, gratuita, sem edição paga — confirmado no Packagist) como dependência obrigatória nas versões atuais (não mais opcional). Nenhum pacote adicional de QR entra no projeto; o SVG é gerado a partir da URI `otpauth://` com o que o próprio pacote já resolve. `pragmarx/google2fa` (**MIT**, gratuita) chega como dependência transitiva do Fortify e é o que de fato gera/valida o código TOTP dentro das Actions.
- **User model:** compõe o comportamento equivalente ao trait `Laravel\Fortify\TwoFactorAuthenticatable` (QR SVG, decodificar segredo, validar código, trocar código de recuperação) — trait que é só métodos de instância, sem registrar nada em rota/HTTP. Pode ser adotado diretamente (compõe com `HasRoles`, `Notifiable`, `AuditableTrait`, que o `User` já usa) ou reimplementado em poucas linhas caso se prefira não expor métodos nomeados por um pacote externo na superfície pública do domínio — decisão de implementação, não desta ADR.

### Enforcement de setup (obrigar quem precisa a configurar)

Novo middleware `EnsureTwoFactorIsConfirmed`, alias **`2fa.confirmado`**, no mesmo mecanismo do `senha.trocada`: bloqueio **incondicional, sem prazo de carência**, aplicado a todas as rotas autenticadas, exceto a própria tela de configuração de 2FA e `logout`.

Sem grace period, deliberadamente: BR-804 diz "obrigatório", e um prazo de carência é o mecanismo clássico pelo qual "2FA obrigatório" nunca chega a ser ativado por ninguém. O precedente já em produção (`senha.trocada`) também não tem prazo. Com uma ou duas contas afetadas (admin/finance), a complexidade de controlar uma contagem regressiva (coluna extra, ramo extra no middleware, cópia extra na tela, caminho extra de teste) não se paga.

Ordem na cadeia de middleware: `conta.ativa` → `senha.trocada` → `2fa.confirmado` — não dá para configurar 2FA ainda com a senha provisória ativa.

### Enforcement de login (o desafio a cada entrada — decisão necessária, não coberta em nenhum ADR anterior)

2FA "configurado uma vez e nunca mais perguntado" não é 2FA, é decoração. Este ADR precisa decidir o desafio por sessão, porque nenhum documento anterior o fez:

Ao autenticar e-mail+senha com sucesso, se `hasTwoFactorEnabled()`, a sessão **não é promovida** ainda — nada de `Auth::login()`. O id do usuário fica numa chave de sessão própria, de curta duração (poucos minutos), e a pessoa é redirecionada a uma tela de desafio (Inertia) pedindo o código TOTP ou um código de recuperação. Só a confirmação correta chama `Auth::login()`, regenera a sessão e libera o app. Isso muda `AuthenticatedSessionController`/`LoginRequest` hoje existentes — ver dívida abaixo.

A tela de desafio oferece uma exceção a esta regra — ver a subseção seguinte.

### Dispositivo lembrado ("lembrar por 30 dias") — exceção ao desafio, não ao setup

Pedido do dono na aprovação desta ADR, para reduzir o atrito de digitar um código a cada entrada no mesmo computador de sempre. **Não existe de graça no Fortify**: as quatro Actions usadas (§ Decisão) não têm noção de "dispositivo confiado" — isso é desenhado aqui, como parte da decisão de enforcement de login, não emprestado de pacote nenhum.

**Mecanismo:** ao concluir o desafio com um **TOTP válido** (não com recovery code — ver adiante), a tela oferece a opção "lembrar este dispositivo por 30 dias". Se marcada:
- Gera-se um token opaco e aleatório (comprimento equivalente a `Str::random(60)`, mesma ordem de grandeza dos tokens de "remember me" que o próprio Laravel já usa).
- O token vai para um cookie **`HttpOnly`, `Secure`, `SameSite=Lax`** — a mesma política de atributos que o ADR-0005 já fixou para o cookie de sessão do Sanctum; nenhuma política nova a inventar.
- O cookie **não é a única credencial**: ele referencia uma linha numa tabela própria do módulo Identity (nome ilustrativo: `two_factor_remembered_devices`) com, no mínimo, `id`, `user_id` (FK), `device_id` (identificador público aleatório, não sequencial — mesma razão do `public_id` do `User`), **`token_hash`** (hash do token, nunca o token em texto puro — o cookie perdido/roubado do banco sozinho não autentica nada), `user_agent` (truncado, mesmo padrão de `security_events`), `ip_address` de primeiro uso, `created_at`, `last_used_at`, `expires_at`.
- **Por que tabela própria e não só um cookie assinado/criptografado sem estado:** um cookie autocontido (JWT-like, assinado com a `APP_KEY`) não pode ser revogado individualmente — revogar exigiria invalidar a chave de assinatura inteira, derrubando *todos* os dispositivos lembrados de *todos* os usuários de uma vez. O dono pediu explicitamente a possibilidade de esquecer dispositivos (ver revogação abaixo), que exige um registro por dispositivo. É a mesma razão pela qual `security_events`/`audits` existem como tabela, não como log de arquivo.
- Nas próximas entradas, com o cookie presente e válido (hash confere, não expirou, `expires_at` no futuro), o desafio TOTP é **pulado** — login segue direto de e-mail+senha para sessão autenticada, exatamente como hoje sem 2FA nenhum. Renova-se `last_used_at` a cada uso (não `expires_at`: os 30 dias contam da confirmação, não do último uso — decisão que evita que um cookie roubado ganhe validade perpétua só por ser usado repetidamente antes de expirar).

**Recovery code nunca gera "lembrar este dispositivo":** só uma confirmação de TOTP genuína pode marcar o dispositivo como confiável. Login por código de recuperação já é, por si, o caminho sensível/anômalo desta ADR (`TwoFactorRecoveryCodeUsed`, abaixo) — conceder 30 dias de bypass exatamente no momento em que alguém está a usar o mecanismo de "perdi o TOTP" anularia a proteção que o dispositivo lembrado pressupõe (posse comprovada do segundo fator). Consequência prática: quem entra com recovery code enfrenta o desafio de novo no próximo login, mesmo no mesmo computador — até confirmar um TOTP válido uma vez.

**Revogação — quando os 30 dias são cortados antes da hora.** Todos os dispositivos lembrados de uma conta são invalidados (linhas apagadas ou marcadas expiradas) quando:
- o 2FA é desabilitado ou reconfigurado (`DisableTwoFactorAuthentication`/`EnableTwoFactorAuthentication` rodam de novo) — um segredo novo não deveria herdar a confiança do segredo antigo;
- a senha é trocada — mesmo racional de qualquer "logout em todos os dispositivos" já previsto em [docs/18-Usuarios §3.5](../18-Usuarios/README.md);
- ação manual "esquecer todos os dispositivos", disponível ao próprio usuário (tela de configuração de 2FA) e ao admin sobre a conta de terceiros (mesma alçada de `changeStatus`/`assignRoles` já reservada à Policy, não ao atalho do admin).

**Auditoria:** um dispositivo sendo lembrado pela primeira vez é sinal relevante — ver o novo caso `TwoFactorDeviceRemembered` na seção Trilha de segurança abaixo.

### Recovery codes

8 códigos, no formato do próprio Fortify (dois blocos alfanuméricos de 5 caracteres separados por hífen), gerados na confirmação inicial do 2FA. Usar um código o consome e regenera **apenas aquele** (mantém sempre 8 válidos, cada um de uso único). Regeneração completa dos 8 é ação própria, atrás de reconfirmação de senha atual — mesmo padrão `current_password` que `ForcedPasswordController::update()` já usa.

### Trilha de segurança

`TwoFactorEnabled` e `TwoFactorDisabled` (já reservados) passam a ser emitidos de fato: o primeiro quando `ConfirmTwoFactorAuthentication` confirma o segredo, o segundo quando `DisableTwoFactorAuthentication` roda.

Este ADR acrescenta **três casos novos** ao enum `SecurityEventType` — consequência direta de decidir a implementação, registrada aqui para não surpreender quem leu só o ADR-0005:
- `TwoFactorRecoveryCodesRegenerated` (`two_factor.recovery_regenerated`) — todos os 8 códigos foram renovados.
- `TwoFactorRecoveryCodeUsed` (`two_factor.recovery_used`) — um código de recuperação autenticou um login. **Sensível** (`isSensitive() = true`): é o sinal de "perdi o celular"/"o app quebrou", exatamente o tipo de evento que a [pasta 26](../26-Auditoria/README.md) existe para capturar — ao contrário de um TOTP normal validado com sucesso, que é ruído de fundo saudável e não gera evento próprio (mesmo raciocínio que já isenta `login.ok`).
- `TwoFactorDeviceRemembered` (`two_factor.device_remembered`) — um dispositivo novo passou a ser confiado por 30 dias. **Sensível**: cada dispositivo lembrado é 30 dias em que o desafio deixa de proteger o login, então saber quando e onde isso foi concedido é o mínimo de rastro que compensa o atrito reduzido. Caso próprio em vez de reaproveitar `RecordSuccessfulLogin` com contexto extra — pelo mesmo motivo que o enum já é granular por fato (`UserInvited` separado de `UserStatusChanged` separado de `UserRolesChanged`): permite filtrar/relatar "quantos dispositivos novos confiados por conta/período" sem misturar com o ruído de login comum, e um evento com nome próprio não depende de ninguém lembrar de inspecionar o JSON de contexto de um evento genérico.

Revogar dispositivos lembrados (seção anterior) é apagar/expirar linhas da tabela própria, não um fato que precise de um quarto caso no enum — o dado já fica implícito em `expires_at`/ausência da linha; um evento de auditoria para isso seria redundante com o que a própria tabela já revela.

### Rate limiting / anti brute-force

Reaproveita `RateLimiter` (núcleo do Laravel, já em uso em `LoginRequest`, **zero dependência nova**) em dois pontos distintos:
- **Confirmação do código durante o setup** (`ConfirmTwoFactorAuthentication`): chave por usuário autenticado, 5 tentativas/minuto — mesmos números que `LoginRequest::ensureIsNotRateLimited()` já usa hoje para senha.
- **Desafio de login** (TOTP ou recovery code): chave por usuário-alvo + IP, 5 tentativas/minuto; estourar o limite invalida o desafio pendente e devolve ao login completo — não deixa a mesma sessão de desafio tentando indefinidamente contra um único código de 6 dígitos.

## Alternativas consideradas

### Alternativa A — `pragmarx/google2fa` "puro" (sem Fortify) + `bacon/bacon-qr-code`, Service/Controller 100% autorais
Geração/validação de TOTP direto com `pragmarx/google2fa` (MIT) e QR com `bacon/bacon-qr-code` (BSD-2-Clause) ou `simplesoftwareio/simple-qrcode` (que por baixo também usa Bacon/Imagick) — ambas gratuitas. Enable/Confirm/GenerateRecoveryCodes/Disable escritos do zero no módulo Identity, no padrão `criar-service`.

**Prós:** dependência mínima e proporcional ao uso real — nenhum pacote cujo propósito publicado é "ser todo o backend de auth" instalado só para emprestar 4 classes; controle total sobre a superfície da API; nenhuma versão do Fortify pode nos surpreender reorganizando algo que não pedimos.
**Contras:** reimplementa exatamente o que as Actions do Fortify já resolvem — geração de segredo, comparação de janela de tempo tolerante a *clock drift*, formato e ciclo de vida dos códigos de recuperação, invalidação de código de uso único. É código de segurança escrito à mão, com maior chance de um erro sutil (ex.: comparação não constante-no-tempo, janela de tolerância mal calibrada) que uma biblioteca testada por milhares de projetos já não cometeria. Mais testes nossos para cobrir o que um pacote maduro já cobre.
**Descartada por ora:** o ganho (dependência mais enxuta) é real mas menor que o risco (reinventar a parte mais sensível a erro). Fica como alternativa natural se o gatilho de revisão do Fortify (abaixo) se concretizar — a migração é barata, porque o `pragmarx/google2fa` já está disponível transitivamente.

### Alternativa B — TOTP artesanal, sem nenhum pacote (RFC 6238 implementado à mão com `hash_hmac`)
**Prós:** zero dependência nova, controle absoluto.
**Contras:** é reimplementar um protocolo criptográfico do zero — o conselho unânime da literatura de segurança é não fazer isso. Um detalhe sutil errado (padding do contador, tolerância de janela, comparação vulnerável a *timing attack*) vira uma falha de autenticação real, não cosmética, e ninguém revisaria esse código além do próprio autor.
**Descartada:** o risco não se paga por nenhuma economia de dependência — `pragmarx/google2fa` já é gratuito e maduro; não há razão para pagar o custo de segurança de escrever a mesma coisa pior.

## Consequências

**Positivas:**
- Reaproveita código maduro e testado pela comunidade Laravel na parte mais sensível a erro sutil (TOTP, códigos de recuperação, invalidação de uso único) — menos código nosso numa superfície criptográfica.
- Os nomes de coluna já migrados batem exatamente com o que as Actions esperam — zero migration nova, zero renomeação, zero retrabalho no que já foi escrito.
- Todos os pacotes envolvidos (`laravel/fortify`, `pragmarx/google2fa`, `bacon/bacon-qr-code`) são gratuitos, com licença permissiva (MIT/BSD-2-Clause) e sem variante paga ou SaaS de terceiros — atende à restrição do dono.
- Nenhuma rota/controller/view/config do Fortify entra em execução — a única porta de entrada do app continua sendo os controllers Inertia do módulo Identity; não recria o "sistema paralelo" que o ADR-0019 e a pasta 05 §5 já rejeitaram para outros casos.
- O enforcement de setup replica um padrão já em produção (`senha.trocada`) — mecanismo, nome de convenção e forma de teste já conhecidos, nada a inventar do zero.
- O dispositivo lembrado ataca o principal risco de adoção de um 2FA "obrigatório sem carência": se o desafio incomodasse a cada entrada, a tentação de contornar ou pedir para desabilitar a regra seria maior. Reduzir o atrito no dispositivo de sempre, sem abrir mão do desafio em dispositivo novo, é o que torna a obrigatoriedade sustentável no dia a dia.

**Negativas / dívidas assumidas:**
- `laravel/fortify` é uma dependência de peso desproporcional ao uso real: seu propósito publicado é ser todo o backend de autenticação (login, registro, verificação de e-mail, reset de senha, 2FA, passkeys), e usamos 4 classes de dezenas que ele expõe. Não há garantia formal de "API pública estável" para uso isolado das Actions — mitigado por travar a versão minor no `composer.json` e revisar o changelog antes de qualquer atualização; o precedente do próprio Jetstream oficial (que consome as Actions do mesmo jeito) reduz o risco, mas não o elimina.
- O login ganha um passo novo que não existe hoje: `AuthenticatedSessionController`/`LoginRequest` precisam de uma tela de desafio intermediária, uma sessão não-autenticada temporária e testes de feature cobrindo o caminho completo (senha ok → desafio → TOTP certo/errado → recovery code → limite de tentativas). Trabalho real, não trivial, a ser planejado na implementação.
- Três casos novos em `SecurityEventType` (`TwoFactorRecoveryCodesRegenerated`, `TwoFactorRecoveryCodeUsed`, `TwoFactorDeviceRemembered`) — divergência do que o ADR-0005 tinha deixado reservado; é consequência direta e necessária de decidir "como", registrada aqui para quem só conhecia os dois casos originais.
- Contas `admin`/`finance` já existentes na base, no primeiro login após este recurso entrar em produção, param para configurar 2FA antes de acessar qualquer tela. Não é um lockout real — a tela de setup fica sempre acessível, igual ao `senha.trocada` — mas é uma fricção operacional que precisa ser comunicada antes do deploy (hoje: só o Diego como `admin`).
- Se o model `User` adotar o trait `TwoFactorAuthenticatable` do Fortify, ele passa a expor métodos nomeados e versionados por um pacote externo na sua superfície pública — perda leve de controle sobre o domínio, mitigável reimplementando os poucos métodos necessários à mão (decisão de implementação).
- **O dispositivo lembrado é, por desenho, um bypass de 2FA por até 30 dias — e isso precisa estar dito, não escondido atrás da conveniência.** Um cookie de dispositivo lembrado roubado (XSS no navegador, malware no computador, dispositivo físico furtado/acessado sem tela de bloqueio) equivale, durante a janela de validade, a autenticar sem segundo fator: exatamente o que o BR-804 existe para evitar. `HttpOnly`/`Secure`/`SameSite=Lax` reduzem os vetores mais comuns (não elimina malware local nem furto do dispositivo desbloqueado); o `token_hash` em vez do token em claro reduz o dano de um vazamento do banco isoladamente, mas não o de um cookie exfiltrado do navegador. A mitigação real é a revogação (troca de senha, reconfiguração de 2FA, "esquecer todos os dispositivos") — o que significa que a superfície de dano de um cookie roubado depende de alguém perceber a anomalia a tempo; sem uma tela de "dispositivos confiados" para o próprio usuário revisar (não especificada aqui, fica de fato para a implementação/UX), a detecção é passiva. É uma dívida deliberada, aceita porque o dono pesou o atrito de UX como custo maior que essa janela de exposição residual — mas não pode ser lida como risco zero.
- Mais escopo de implementação do que a versão original desta ADR previa: nova tabela (migration própria), lógica de emissão/validação/expiração/revogação de cookie, checkbox na tela de desafio, e testes de feature cobrindo dispositivo lembrado válido, expirado, revogado por troca de senha/2FA, e o caminho "recovery code não lembra dispositivo". Trabalho real, adicional ao já registrado acima para o desafio de login em si.

**Gatilhos de revisão:**
- O Fortify remover, renomear ou quebrar o comportamento das Actions usadas, numa versão minor/major, sem aviso equivalente no changelog → migrar para a Alternativa A (`pragmarx/google2fa` direto), que já está disponível transitivamente e reduz a mudança a trocar quem chama a lib, não a lógica.
- WebAuthn/passkeys entrar em escopo antes da fase 7 planejada (docs/18-Usuarios §7) → reabrir, porque o mesmo `laravel/fortify` já resolve isso e muda o cálculo de custo/benefício de manter só as 4 Actions de TOTP.
- Necessidade de 2FA fora do login por sessão Inertia — por exemplo, token de máquina (Sanctum) exigindo segundo fator para alguma operação sensível → este ADR cobre só o login humano por sessão; extensão a tokens pede decisão própria.
- Volume anormal de `TwoFactorRecoveryCodeUsed` para uma mesma conta → sinal de dispositivo perdido recorrente ou tentativa de abuso; avaliar reforçar a política (ex.: exigir reconfirmação do TOTP após N usos de código de recuperação).
- Os 30 dias do dispositivo lembrado se mostrarem **longos demais** (um incidente decorrente de dispositivo comprometido revelar que a janela deveria ser menor) ou **curtos demais** (reclamação recorrente de desafio repetido apesar do dispositivo estar marcado — ex.: cookies limpos com frequência pelo navegador) → ajustar o prazo. É o dono quem definiu o valor inicial; qualquer mudança de prazo é dele também, não uma decisão técnica isolada.
- Um incidente real de cookie de dispositivo lembrado comprometido (mesmo que só suspeito) → revisar se a mitigação por revogação é suficiente ou se o mecanismo precisa de uma tela própria de "dispositivos confiados" para o usuário auditar/revogar individualmente (hoje só "esquecer todos", não "esquecer este").
