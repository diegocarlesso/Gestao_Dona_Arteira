---
name: criar-documentacao
description: Cria ou atualiza documentação do projeto (docs/) aplicando os templates, cabeçalhos, convenções de nome e cross-links — e mantém os índices em dia. Use para qualquer documento novo, ADR, BR, runbook ou revisão pós-mudança.
---

# Skill: Criar Documentação

## Objetivo
Documento na pasta certa, no template certo, com status/dono/data, linkado nos índices — mantendo o princípio docs-first auditável.

## Pré-requisitos
1. Identificar o tipo: doc de módulo, ADR, regra de negócio, runbook ou integração → template correspondente em `docs/_templates/`.
2. Verificar duplicação: já existe doc que cobre o assunto? (atualizar > criar).

## Entradas
Assunto, tipo, pasta de destino (00–30), decisões/fatos a registrar, docs relacionados.

## Fluxo
1. Nome do arquivo: kebab-case sem acentos, prefixo numérico sequencial na pasta (`03-nome-do-assunto.md`).
2. Cabeçalho obrigatório: `> **Status:** … · **Última atualização:** AAAA-MM-DD · **Responsável:** …` + refs (ADRs/BRs).
3. Seguir a estrutura do template (objetivo → responsabilidades → fluxo → dependências → boas práticas → riscos → evoluções futuras → perguntas em aberto).
4. Diagramas em Mermaid no próprio Markdown; tabelas para matrizes/de-paras.
5. Cross-links relativos nos DOIS sentidos (o doc novo aponta para os relacionados; os relacionados passam a apontar para ele).
6. Termos novos → entrada no glossário (docs/29).
7. Atualizar índices: `docs/README.md` se pasta nova/doc principal; `docs/27-ADR/README.md` se ADR; lista de documentos no README da pasta.
8. ADR: status `Proposto` se depende de aprovação do dono; nunca editar ADR aceito (novo ADR substitui).

## Saídas
Documento no padrão + índices e glossário atualizados.

## Critérios mínimos
Encontrável em ≤ 3 cliques a partir de `docs/README.md`; sem conteúdo duplicado de outro doc (link em vez de cópia); pt-BR claro.

## Checklist final
- [ ] Template e cabeçalho completos?
- [ ] Nome/pasta/numeração nas convenções?
- [ ] Links relativos válidos nos dois sentidos?
- [ ] Índices atualizados? Glossário?
- [ ] Riscos e evoluções futuras preenchidos (não "N/A" por preguiça)?
