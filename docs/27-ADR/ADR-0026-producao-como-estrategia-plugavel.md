# ADR-0026: Produção como estratégia plugável — reuso do ERP por outra empresa

> **Status:** Proposto
> **Data:** 2026-08-06 · **Decisores:** dono (direção), chief-architect
> **Módulos afetados:** 08 (Produção), 28 (Roadmap); relacionado a [ADR-0001](ADR-0001-monolito-modular.md), [ADR-0023](ADR-0023-producao-e-pintura-nao-fundicao.md), [ADR-0024](ADR-0024-quarentena-de-secagem.md)

## Contexto

O dono manifestou, em 2026-08-06, a intenção de reaproveitar este ERP para
uma **segunda empresa**, ainda sem cliente/processo concreto definido. A
preocupação: se cada empresa exigir personalização profunda do sistema,
implantar uma segunda vira um fork, não uma configuração.

Hoje o sistema é deliberadamente específico. [ADR-0001](ADR-0001-monolito-modular.md)
escolheu monólito modular para uma operação pequena, single-tenant,
hospedagem compartilhada, equipe de uma pessoa. A maior parte dos módulos já
existentes ou em construção — Identity, Catalog, Estoque (ledger), Vendas,
Financeiro, Integrações — **não modela nada específico do negócio da Dona
Arteira**: são cadastro, saldo, pedido, título, sincronização — o vocabulário
de qualquer PME que vende produto físico.

O módulo genuinamente acoplado ao processo real da Dona Arteira é
**Produção**, ainda não implementado (Gate 03, bloqueado aguardando
aprovação de [ADR-0023](ADR-0023-producao-e-pintura-nao-fundicao.md)/
[ADR-0024](ADR-0024-quarentena-de-secagem.md) e as entrevistas da
[pasta 30](../30-Dominio-da-Dona-Arteira/README.md)). O ADR-0023 modelou a
etapa de produção como **pintura sobre peça crua comprada** — um fato
específico desta empresa, validado por entrevista, não um modelo genérico de
"produção" com esse comportamento plugado por cima. Uma segunda empresa que
funda, monta ou apenas revende sem transformação exigiria um processo
diferente.

Generalizar o sistema inteiro agora — sem uma segunda empresa real para
validar contra qual abstração desenhar — é abstrair no escuro: o próprio
CLAUDE.md do projeto veta desenho para requisito hipotético ("não desenhar
para necessidades futuras hipotéticas"), e um palpite errado de abstração
custa mais para desfazer depois do que teria custado desenhar específico e
extrair o genérico com um segundo caso real na mão. Generalizar agora também
atrasaria o Gate 03, que já está no caminho crítico do roadmap.

## Decisão

**Não generalizar o sistema inteiro agora.** Módulos fora de Produção
continuam como estão — já são neutros ao tipo de negócio, não precisam de
mudança para este objetivo.

**Produção nasce, no desenho do Gate 03, com um único ponto de extensão**:
um "modo/estratégia de produção" do qual **"pintura sobre peça crua
comprada"** ([ADR-0023](ADR-0023-producao-e-pintura-nao-fundicao.md)) é a
única implementação necessária hoje. Uma segunda empresa com processo
diferente (fundição, montagem, revenda sem etapa de transformação) entraria
como uma **nova implementação desse ponto de extensão** — não como reforma
do núcleo de Produção nem dos demais módulos.

**Multi-tenancy (uma instalação servindo várias empresas ao mesmo tempo)
fica fora de escopo desta decisão.** Se e quando uma segunda empresa real
aparecer, a escolha entre instância separada (mesmo código, deploy próprio)
e multi-tenant único é feita então, com o caso concreto na mão.

Este ADR não desbloqueia o Gate 03 — a aprovação de ADR-0023/0024 e as
entrevistas da pasta 30 continuam pré-requisito. Ele registra a forma como o
módulo será desenhado quando esse trabalho começar.

## Alternativas consideradas

### Alternativa A — Generalizar o sistema inteiro agora (multi-tenant desde já)
Reformular o modelo de dados e os módulos para suportar múltiplas empresas
com configuração paramétrica ampla, antes de qualquer segundo cliente real.
Prós: nenhuma migração futura de "específico para genérico". Contras:
abstração especulativa sem caso real para validar contra; atrasa o Gate 03,
que já está bloqueado por outros motivos; contraria a disciplina do próprio
projeto contra desenho para requisito hipotético. Descartada.

### Alternativa B — Manter tudo específico até a segunda empresa aparecer, sem ponto de extensão em Produção
Não desenhar nenhuma estratégia plugável agora; se uma segunda empresa
aparecer, extrair a abstração de dentro do código já em produção (com dados
reais e testes existentes). Prós: zero esforço extra hoje. Contras: extrair
um ponto de extensão de código já mesclado e operando é mais arriscado e
caro do que desenhá-lo desde o início do Gate 03, quando o módulo ainda não
existe. Descartada em favor da C.

### Alternativa C — Ponto de extensão só em Produção, resto do sistema inalterado (adotada)
Desenha a única parte do sistema onde já há sinal concreto de que pode
variar (o processo de transformação), sem tocar no resto nem introduzir
multi-tenant. Equilibra o custo de hoje (baixo — é uma escolha de desenho
dentro de um módulo que ainda vai ser construído) com o risco de reforma
futura (também baixo — o ponto de extensão já existe quando a segunda
empresa aparecer).

## Consequências

**Positivas:**
- Uma segunda empresa com processo de produção diferente não exige reforma
  do núcleo de Produção nem dos demais módulos — só uma implementação nova
  do ponto de extensão.
- Não atrasa nem redesenha o que já está pronto (Gates 00–02) nem o que está
  no caminho crítico (Gate 03).
- Mantém a disciplina do projeto de não abstrair sem sinal concreto de
  variação — o único lugar onde se aposta em genericidade é onde já se sabe
  que varia.

**Negativas / dívidas assumidas:**
- O ponto de extensão é desenhado sem um segundo caso real ainda — corre o
  risco de ficar levemente errado quando a segunda empresa aparecer de
  verdade. Risco contido: é a única aposta de genericidade do sistema.
- Se a segunda empresa exigir diferença **fora** de Produção (regime fiscal,
  moeda, unidade de negócio), este ADR não cobre — precisaria de ADR novo
  quando acontecer.
- Multi-tenancy adiada significa que, havendo duas empresas reais ao mesmo
  tempo, o caminho inicial provável é deploy separado (duas instâncias) —
  custo operacional maior a curto prazo que uma instalação compartilhada,
  mas reversível e mais simples de acertar sem dado real de uso conjunto.

**Gatilhos de revisão:**
- Uma segunda empresa real aparecer com processo de produção definido →
  revisar este ADR com o caso concreto na mão; desenhar a implementação nova
  do ponto de extensão (ou confirmar que "pintura" ainda é a única
  necessária).
- Necessidade real de servir duas ou mais empresas na mesma instalação ao
  mesmo tempo → novo ADR de multi-tenancy.
- Segunda empresa exigir diferença fora de Produção → novo ADR específico
  para o módulo afetado.
