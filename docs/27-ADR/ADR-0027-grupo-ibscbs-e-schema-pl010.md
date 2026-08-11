# ADR-0027: Grupo IBS/CBS na NF-e — omissão configuração-a-configuração, não bloqueio, e troca do pacote de schema para PL_010_V1.30

> **Status:** Proposto
> **Data:** 2026-08-11 · **Decisores:** chief-architect, fiscal-specialist, nfe-specialist
> **Módulos afetados:** 13 (Fiscal), 14 (NF-e), 04 (Banco de Dados)

## Contexto

O dono pediu para implementar o grupo IBS/CBS (reforma tributária, LC 214/2025) no `MontarXmlNfe.php` depois de ler que a SEFAZ-RS passou a rejeitar NF-e sem esses campos a partir de 03/08/2026. Pesquisa mostrou que esse prazo vale para o **regime regular** (Lucro Real/Presumido); **Simples Nacional está isento até 04/01/2027** (LC 214/2025, art. 348, III, "c"), e `MontarXmlNfe` só emite para Simples Nacional (`EmissaoInvalida::regimeSemSuporte` recusa qualquer outro CRT) — a Dona Arteira é Simples Nacional (documentado, ainda "💡 confirmar" com o contador).

Duas decisões distintas, mas que nasceram da mesma investigação, por isso um ADR só:

**1. Como tratar a ausência de configuração fiscal do grupo IBS/CBS.** O padrão já estabelecido no `MontarXmlNfe` para PIS/COFINS é: falta o CST configurado → `EmissaoInvalida::tributosFederaisNaoConfigurados()`, a emissão para. Aplicar o mesmo padrão ao IBS/CBS bloquearia **toda** emissão até o contador responder H-05/H-06 da pauta — mas PIS/COFINS são obrigatórios hoje, e IBS/CBS **não é**, para o nosso regime, neste ano. Tratar "não configurado" como pendência bloqueante inventaria uma obrigação que a lei ainda não impõe à empresa.

**2. O pacote de schema usado para montar/validar o XML (`CanalSefaz::SCHEMAS`, hoje `PL_009_V4`) é anterior à reforma tributária** — não tem o elemento `IBSCBS` no XSD (`grep -l IBSCBS schemes/PL_009_V4/*.xsd` não encontra nada). A biblioteca `sped-nfe` (já instalada, v5.2.8) distribui pacotes mais novos, até `PL_010_V1.30`, com o grupo completo (`Traits/TraitTagDetIBSCBS.php`, usado por `Make.php`). Sem trocar o pacote, qualquer XML com `IBSCBS` reprovaria a própria validação que o projeto já roda no CI (`tests/Feature/Fiscal/SpedNfeGatewayTest.php`, `Validator::isValid($xml, xsdDaNfe())`) — a estrutura pedida não existe sem essa troca.

## Decisão

**Grupo IBS/CBS é opcional por configuração, nunca bloqueante.** `TaxProfile` ganha 5 colunas nuláveis (`ibscbs_cst`, `ibscbs_cclasstrib`, `ibs_uf_aliquota`, `ibs_mun_aliquota`, `cbs_aliquota`) e um método `ibscbsConfigurado(): bool` que só é verdadeiro com os 5 preenchidos (configuração parcial conta como ausente — nunca um grupo pela metade). `MontarXmlNfe::itens()` chama `Make::tagIBSCBS()` por item **só quando** `ibscbsConfigurado()`; sem isso, o XML sai exatamente como hoje, sem o grupo. `tax_profiles` continua nascendo vazio nesses campos — populá-los é decisão do contador, não do deploy (mesma filosofia da migration original de `tax_profiles`).

**Pacote de schema trocado para `PL_010_V1.30`** (o mais novo distribuído pela lib instalada). Comparação estrutural dos dois `leiauteNFe_v4.00.xsd` (nomes de elemento) mostra mudança **aditiva** — tudo que existe em `PL_009_V4` continua existindo em `PL_010_V1.30`; os elementos novos (`IBSCBS`, `IS`, `gCompraGov`, `DFeReferenciado` etc.) não substituem nada. A troca foi confirmada rodando a suíte inteira de `tests/Feature/Fiscal` contra o schema novo antes de qualquer mudança de código de montagem, para separar "a troca de pacote quebrou algo" de "o código novo tem bug".

## Alternativas consideradas

### Alternativa A — Tratar ausência de IBS/CBS como `EmissaoInvalida` (mesmo padrão do PIS/COFINS)
**Prós:** um padrão só para "dado fiscal obrigatório ausente" em todo o arquivo; menos ramificação para entender.
**Contras:** bloquearia toda emissão (inclusive as que já funcionam hoje, ICMS/PIS/COFINS/CFOP/CSOSN completos) por um campo que a lei não exige da empresa até 2027. Trocaria uma trava de segurança fiscal por uma trava de calendário — o oposto do que a pasta 13 já define como objetivo (não travar a operação por regra não confirmada, BR-601 tem a mesma nota).
**Descartada.**

### Alternativa B — Não mexer no pacote de schema agora; só documentar a limitação
**Prós:** zero risco de regressão nos testes existentes; escopo menor.
**Contras:** o pedido explícito era "implementar o grupo IBS/CBS" — sem o schema novo, o código ficaria escrito mas nunca testável (`Validator::isValid` reprovaria qualquer nota que o usasse), o que equivale a não ter implementado nada de verificável. A pasta 14 §8 já registra "NTs da reforma exigirem atualização rápida da lib" como risco de probabilidade **certa** — adiar a troca de pacote só adia o mesmo trabalho para quando for mais urgente (2027, com menos tempo de sobra).
**Descartada** como caminho permanente; poderia servir de meio-termo temporário, mas o diff aditivo tornou o risco baixo o bastante para não valer a espera.

### Alternativa C — Omissão configurável + troca de pacote (decisão)
**Prós:** desbloqueia a implementação pedida sem inventar obrigação legal inexistente; o grupo fica pronto e testado, então quando o contador responder H-05/H-06 (ou quando 2027 chegar), ativar é preencher cadastro, não escrever código sob pressão de prazo. Pacote de schema atualizado de uma vez, com o mesmo rigor de teste (suíte inteira, XSD oficial) que todo o resto do módulo Fiscal já usa.
**Contras:** dois comportamentos de "dado fiscal ausente" convivendo no mesmo arquivo (`EmissaoInvalida` para PIS/COFINS, omissão silenciosa para IBS/CBS) — mitigado com comentário explícito no código apontando para este ADR, para quem ler não presumir inconsistência.

## Consequências

**Positivas:**
- A Dona Arteira sai na frente da obrigação de 2027 sem sofrer as consequências de emitir um grupo mal configurado antes da hora — quando ativar, é configuração, não deploy sob pressão.
- O pacote de schema deixa de estar preso a um layout pré-reforma; o próximo campo novo (IS, monofásico, se algum dia se aplicar) tem onde nascer sem outra troca de pacote.
- Nenhum comportamento hoje testado muda: emissão sem IBS/CBS configurado é byte-a-byte o XML de antes (a não ser pelo pacote de validação, que é aditivo).

**Negativas / dívidas assumidas:**
- Dois padrões de "campo fiscal ausente" no mesmo arquivo (bloqueia vs. omite) — documentado aqui e no comentário do código, para não parecer inconsistência não intencional.
- `PL_010_V1.30` pode não ser a versão final que a SEFAZ exigir quando a obrigação realmente entrar em vigor para o nosso regime (2027) — Notas Técnicas continuam saindo; a lib precisará de novas atualizações de pacote entre agora e lá.

**Gatilhos de revisão:**
- Contador confirmar (H-05/H-06 da pauta) que a Dona Arteira deve emitir IBS/CBS antes de 2027, ou mudar de regime tributário → popular `tax_profiles` com os 5 campos; nenhuma mudança de código necessária.
- `nfephp-org/sped-nfe` publicar pacote de schema mais novo que `PL_010_V1.30` (a lib já teve v1.10→v1.40 em menos de um ano) → reavaliar a troca com o mesmo processo (diff estrutural + suíte inteira antes do código).
- Data-limite de 04/01/2027 se aproximar sem confirmação do contador → revisitar como pendência de prazo real, não mais hipótese de calendário.
