# ADR-0019: Laravel + Inertia + React em vez de SPA separada (substitui o ADR-0004)

> **Status:** ✅ **Aceito** · **Data:** 2026-07-22 · **Decisores:** dono do produto, com recomendação técnica
> **Substitui:** [ADR-0004](ADR-0004-spa-react-separada.md) · **Revisa o escopo de:** [ADR-0003](ADR-0003-api-first-rest.md), [ADR-0005](ADR-0005-autenticacao-sanctum.md)
> **Módulos afetados:** 05, 06, 07, 22, 23, 25, 03

## Contexto

O [ADR-0004](ADR-0004-spa-react-separada.md) decidiu por uma SPA React separada consumindo a API REST. A [análise crítica do Gate 00 §B1](../00-Visao-Geral/04-analise-critica-gate00.md) já registrava essa como *"a decisão que mais questiono"* e *"a de maior custo recorrente para um solo dev"*, deixando explícito que a premissa deveria ser reconfirmada antes do Gate 01. Ela nunca foi (item F4, em aberto desde 2026-07-03).

Fatos que mudaram a leitura entre julho/03 e julho/22:

1. **O argumento central do ADR-0004 não se sustenta como escrito.** Ele afirma que *"a API REST vai ter que existir de qualquer forma para o WooCommerce"*. Mas a integração Woo consiste em **chamadas de saída** para a API do WooCommerce e em **um punhado de receptores de webhook**. Nada disso exige uma API REST versionada, com contrato OpenAPI e client TypeScript gerado, cobrindo todas as entidades do ERP. A superfície realmente necessária para integração é uma fração da que o ADR-0004 pressupunha.

2. **Nenhum consumidor adicional foi confirmado.** App mobile e marketplaces seguem hipóteses, sem plano nem prazo (F4 nunca respondida).

3. **O escopo contratado é o completo (Gates 01–06)**, estimado em ~2.400 h para uma pessoa. O risco dominante do projeto não é escolher a tecnologia errada — é [não terminar](../00-Visao-Geral/04-analise-critica-gate00.md) (risco R6, *fadiga de solo dev*).

4. **A hospedagem escolhida é compartilhada** ([ADR-0016](ADR-0016-hospedagem.md), Plano B). Uma aplicação única, com um único build e um único deploy, é substancialmente mais simples de operar nesse ambiente do que duas bases servidas no mesmo domínio.

## Decisão

**Usaremos Laravel + Inertia.js + React (Vite + TypeScript) como aplicação única**, em vez de uma SPA React separada consumindo a API REST.

Consequências normativas:

- **Telas do ERP:** componentes React renderizados via Inertia, com props vindas dos controllers. Continua sendo React e TypeScript — muda o transporte, não a linguagem nem a biblioteca de UI.
- **A API REST continua existindo, com escopo reduzido:** apenas o que integrações externas realmente consomem (receptores de webhook, endpoints para parceiros, tokens de máquina). Ela deixa de ser a via de acesso das telas internas.
- **OpenAPI deixa de ser o contrato de toda a superfície** e passa a documentar apenas a API de integração. O fluxo "spec antes do controller" ([ADR-0003](ADR-0003-api-first-rest.md), skill `criar-api`) permanece obrigatório **para os endpoints de integração** e deixa de se aplicar às telas internas.
- **Autenticação:** sessão nativa do Laravel para os usuários humanos; o Sanctum permanece para **tokens de integração** ([ADR-0005](ADR-0005-autenticacao-sanctum.md)). O modo "cookie SPA" do Sanctum deixa de ser necessário. 2FA e RBAC não mudam.
- **Estrutura:** aplicação única, sem monorepo `api/` + `web/`.

## Alternativas consideradas

### Alternativa A — Manter a SPA separada (ADR-0004)
**Prós:** contrato de API exercitado desde o dia 1; frontend trocável; pronta para mobile/marketplace no dia em que existirem.
**Contras:** duas bases de código, dois builds, um contrato OpenAPI e um client gerado a manter em sincronia — trabalho recorrente pago toda semana pela mesma pessoa. Cada campo novo em uma tela atravessa spec → backend → client → frontend.
**Descartada:** o custo recorrente é certo e imediato; o benefício é hipotético e adiado. Se mobile/marketplace se concretizarem, a API de integração já existirá e poderá ser ampliada — o custo de *ampliar* depois é menor que o de *manter* a superfície completa por anos sem consumidor.

### Alternativa B — Blade + Livewire
**Prós:** ainda menos JavaScript; stack Laravel pura.
**Contras:** abandona React e TypeScript, contrariando a stack definida pelo projeto e o conhecimento já acumulado; telas ricas de ERP (grades de produção, tabelas grandes) ficam desconfortáveis.
**Descartada:** perde-se mais do que se ganha.

### Alternativa C — Manter a decisão até o Gate 02 e reavaliar
**Prós:** adia a discussão.
**Contras:** o custo de reverter cresce a cada tela escrita; ao fim do Gate 02 haveria dezenas de endpoints e telas no modelo antigo.
**Descartada:** se a mudança é certa, o momento mais barato é agora — zero linhas de código escritas.

## Consequências

**Positivas:**
- Uma base de código, um build, um deploy. Estimativa de economia: **200–350 h** ao longo dos Gates 01–06.
- Sem client TypeScript gerado nem sincronização de contrato para as telas internas.
- Menos superfície de autenticação (sem CORS, sem cookie cross-site, sem CSRF de SPA).
- Deploy substancialmente mais simples no plano Business ([ADR-0016](ADR-0016-hospedagem.md)).
- Ataca diretamente o risco R6 (fadiga de solo dev), o de maior severidade real do projeto.

**Negativas / dívidas assumidas:**
- **As telas internas deixam de exercitar a API.** Se um app mobile surgir, a API precisará ser ampliada e testada sem o dogfooding que a SPA daria. Mitigação: os endpoints de integração continuam sob contrato OpenAPI e com testes de contrato ([pasta 22](../22-Testes/README.md)).
- **Acoplamento maior entre backend e telas.** Trocar o frontend deixa de ser trivial. Aceito: não há plano de trocá-lo.
- **Retrabalho documental imediato:** as pastas 06 (Frontend), 07 (API) e 23 (Deploy) foram escritas assumindo SPA e precisam de revisão antes do código — ver §Trabalho decorrente.
- **Inertia é uma dependência de arquitetura**, com seu próprio ciclo de vida. Risco baixo (mantido pelo ecossistema Laravel), mas real.
- Reverter para SPA depois custaria reescrever a camada de acesso a dados das telas.

**Gatilhos de revisão:**
- App mobile ou marketplace entrar em plano concreto, com prazo → avaliar ampliar a API de integração (não necessariamente voltar à SPA).
- Um segundo desenvolvedor entrar no projeto com separação clara de front/back → reavaliar.
- Telas de alta interatividade (ex.: painel de produção em tempo real) esbarrarem em limites do Inertia → avaliar ilhas de SPA em rotas específicas, sem reverter o todo.

## Trabalho decorrente (antes de qualquer código — regra docs-first)

| Documento | Revisão necessária | Status |
|---|---|---|
| [06-Frontend](../06-Frontend/README.md) | Reescrever: padrões Inertia, estrutura de páginas, formulários, tabelas — em vez de SPA + client gerado | ✅ **feito em 2026-07-22** |
| [07-API](../07-API/README.md) e [07/01](../07-API/01-fluxo-openapi.md) | Reduzir escopo: contrato passa a cobrir só a API de integração | ✅ **feito em 2026-07-22** |
| [23-Deploy](../23-Deploy/README.md) | Deploy único no plano Business; build de assets no CI, não no host |
| [03-Arquitetura/01-visao-c4](../03-Arquitetura/01-visao-c4.md) | Diagrama de contêineres: uma aplicação, não duas |
| [05-Backend](../05-Backend/README.md) | Controllers retornam respostas Inertia nas rotas internas |
| [22-Testes](../22-Testes/README.md) | Testes de tela passam a ser de feature+Inertia; Vitest para componentes permanece |
| [25-Segurança](../25-Seguranca/README.md) | Ajustar seção de autenticação (sessão em vez de cookie SPA) |
| Skills `criar-api`, `criar-controller`, `criar-crud` | Ajustar ao novo fluxo |
