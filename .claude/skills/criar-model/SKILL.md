---
name: criar-model
description: Cria um Model Eloquent do ERP com invariantes, casts, relações, enums, auditoria e factory — seguindo ADR-0015 (model é o modelo de domínio) e os padrões da pasta 05-Backend. Use ao introduzir qualquer entidade nova.
---

# Skill: Criar Model

## Objetivo
Model Eloquent que carrega o domínio: invariantes locais, tipos ricos (enums/casts/Money), relações explícitas e trilha de auditoria — sem lógica de aplicação (que pertence ao Service).

## Pré-requisitos
1. Tabela existente/planejada via skill `criar-migration` (modelo conceitual em dia).
2. Entidade descrita no doc do módulo (docs/08–19) com agregado/invariantes (docs/02 §4).

## Entradas
Nome da entidade, módulo dono (`app/Modules/<X>/Models`), invariantes (BRs), relações, campos com tipos.

## Fluxo
1. Criar o model no módulo correto com `declare(strict_types=1)`, `$fillable` explícito (nunca `$guarded = []`).
2. Casts: enums nativos para status/tipos; `decimal:2`/`decimal:3` + acessors Money (brick/money) para valores; datas `immutable_datetime`; cifrados (`encrypted`) para segredos.
3. Relações tipadas com return type; escopos nomeados pelo negócio (`scopeOverdue`, `scopeAvailable`).
4. Invariantes locais: validações de estado no próprio model (métodos de transição para máquinas de estado — `markAsPaid()` valida a transição, BR-303) lançando exceção de domínio tipada.
5. Auditoria: implementar `Auditable` (ADR-0012) se entidade de negócio; excluir campos sensíveis do diff.
6. Soft delete SOMENTE se cadastro com inativação (BR-008) — fatos nunca.
7. Factory com estados nomeados (`->paid()`, `->wholesale()`); testes unit das invariantes citando BRs.

## Saídas
Model + enums + factory + testes de invariantes.

## Critérios mínimos
PHPStan nível 8 limpo; nenhuma chamada externa/side effect oculto no model; toda transição de estado passa por método que valida.

## Checklist final
- [ ] Invariantes do agregado (docs/02 §4) implementadas e testadas com BR no nome?
- [ ] Dinheiro via Money nos acessors; nunca float?
- [ ] Enum nativo para todo status/tipo?
- [ ] Auditable configurado (com exclusões de campos sensíveis)?
- [ ] Factory com estados nomeados úteis?
- [ ] Sem `$guarded=[]`, sem lógica de aplicação, sem query pesada em accessor?
