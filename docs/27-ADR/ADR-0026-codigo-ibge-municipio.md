# ADR-0026: Código IBGE do município do destinatário — tabela de referência embarcada, sem dependência externa em tempo de execução

> **Status:** Proposto
> **Data:** 2026-08-10 · **Decisores:** chief-architect, senior-dba, nfe-specialist
> **Módulos afetados:** 10 (Vendas), 14 (NF-e), 04 (Banco de Dados)

## Contexto

O `SpedNfeGateway` (ADR-0025) recusa a emissão quando falta o código IBGE do município do destinatário (`enderDest/cMun`), campo obrigatório no layout 4.00 da NF-e — de propósito, para não aproximar pelo nome da cidade. `customer_addresses` hoje guarda só `city` (texto livre) e `state` (UF); não há de onde ler esse código.

A pergunta é **como preencher esse campo** para os clientes já cadastrados e para os que vierem depois, sem violar a regra 4 da pasta 17 ("rejeição explícita — nada é descartado/aproximado silenciosamente") nem inventar dado geográfico que pareça certo e não seja: código IBGE errado é nota autorizada com endereço fiscal incorreto, o mesmo risco que o gate já existe para evitar.

Três caminhos existem: (a) digitação manual em todo cadastro, (b) consulta a uma API externa de CEP/localidade a cada emissão, (c) uma tabela de referência dos municípios brasileiros, carregada uma vez e consultada localmente.

## Decisão

**Tabela de referência `ibge_municipalities`** (uf, nome, código IBGE de 7 dígitos), semeada a partir dos **dados oficiais do IBGE** (API pública `servicodados.ibge.gov.br/api/v1/localidades/municipios` ou fonte equivalente do próprio IBGE) — nunca digitada ou "lembrada" de memória por quem implementa. A resolução do `city_code` de um endereço é automática: casa `state`+`city` (normalizado — maiúsculas, sem acento) contra a tabela; achou, preenche; não achou com confiança, deixa `null` e o endereço fica com a mesma pendência visível que `Customer::pendencias()` já usa para documento/endereço faltando — **nunca aproxima**. Campo sempre editável à mão no cadastro, para o caso raro de nome de cidade divergente da grafia oficial do IBGE.

## Alternativas consideradas

### Alternativa A — Digitação manual em todo cadastro
**Prós:** zero tabela para manter, zero risco de casamento errado.
**Contras:** ninguém no balcão sabe de cabeça o código de 7 dígitos do IBGE; a operação inventaria "qualquer número" só para o formulário aceitar, e aí a nota sai com código chutado de verdade — exatamente o risco que o gate do ADR-0025 já existe para barrar.
**Descartada** como único caminho; sobra como *fallback* de edição, não como forma primária.

### Alternativa B — Consultar API externa (CEP ou localidades) em tempo de emissão
**Prós:** sempre atualizado, zero dado para manter no banco.
**Contras:** vira dependência externa nova em tempo de execução (ADR-0007 §"nunca síncrono para o caminho crítico" — emissão de NF-e já é assíncrona, mas ganhar uma segunda chamada de rede externa no meio do fluxo é mais um ponto de falha, mais uma credencial, mais um SLA de terceiro para um dado que **não muda** — município não troca de código. Precisaria de ADR próprio por introduzir integração nova (regra 2 do CLAUDE.md).
**Descartada**: o dado é estático por natureza; pagar o custo de uma integração viva por algo que se resolve com uma tabela é desproporcional.

### Alternativa C — Tabela embarcada, sourced do IBGE oficial (decisão)
**Prós:** sem dependência externa em produção; resolução instantânea; dado correto porque vem da fonte oficial, não de memória de LLM nem de digitação; município não muda de código, então a tabela não fica velha na prática (IBGE cria/funde municípios raramente, e quando ocorre é notícia nacional, não silêncio).
**Contras:** ~5.570 linhas para carregar uma vez (seeder, mesmo padrão de `RolePermissionSeeder`/`FinanceCategorySeeder` — idempotente, `firstOrCreate`); casamento por nome pode falhar em grafias divergentes (fallback: edição manual, nunca aproximação automática por similaridade).

## Consequências

**Positivas:**
- Nenhuma nova integração externa nasce (nenhum ADR-0007 novo a escrever); a emissão continua sem depender de rede de terceiro além da própria SEFAZ.
- Dado correto por construção: a tabela vem da fonte oficial, verificada por amostragem (capitais conhecidas) antes de aceitar a carga.
- O comportamento do gate (recusar em vez de aproximar) se estende ao cadastro: sem casamento confiável, fica pendência visível, não código errado.

**Negativas / dívidas assumidas:**
- Tabela requer atualização eventual se o IBGE criar/extinguir/renomear município — improvável, mas é dívida registrada, não esquecida (gatilho abaixo).
- Casamento por nome normalizado pode exigir correção manual em casos de grafia atípica (ex.: abreviações, nomes compostos com hífen); é o preço de não ter CEP estruturado no cadastro hoje.

**Gatilhos de revisão:**
- Cadastro de endereço ganhar CEP como campo obrigatório e estruturado (hoje é texto livre) → revisar se a resolução por CEP (via a mesma tabela, com faixa de CEP por município, ou por serviço) substitui o casamento por nome com mais precisão.
- IBGE publicar mudança de código de município que afete cliente já cadastrado → reseed da tabela (idempotente, não quebra nada existente).
- Volume de pendências de "sem código IBGE" se mostrar alto na prática (> 5% dos endereços) → revisar o casamento por nome, não a decisão de não usar API externa.
