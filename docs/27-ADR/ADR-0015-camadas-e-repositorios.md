# ADR-0015: Camadas Controller→Service→Model e Repository Pattern dosado

> **Status:** Aceito · **Data:** 2026-07-03 · **Decisores:** chief-architect, laravel-specialist
> **Módulos afetados:** 05, 03

## Contexto

O projeto pede Clean Architecture, SOLID, Repository Pattern e Service Layer. Em Laravel, a aplicação literal desses padrões (interfaces para tudo, repositórios encapsulando 100% do Eloquent, entidades separadas dos models) produz camadas de indireção que dobram o custo de manutenção — exatamente o que uma equipe de 1 pessoa não pode pagar. É preciso definir a **dose** que preserva os princípios sem burocracia.

## Decisão

Camadas obrigatórias e papéis fixos (detalhe na pasta 05):
- **Controller fino** (validação/autorização/delegação) — nunca regra ou query.
- **Service/Action é a camada de aplicação**: 1 caso de uso nomeado, dono da transação, dispara eventos. Toda regra de negócio vive em Service/Model — nunca em controller, job ou listener.
- **Model Eloquent é o modelo de domínio** (invariantes locais, relações, casts) — não criamos camada de entidades paralela.
- **Repository apenas onde agrega**: consultas complexas reutilizadas ou nomeadas pelo negócio; CRUD trivial usa Eloquent direto no Service. Interface só quando houver segunda implementação plausível (ex.: `NfeGatewayInterface` — ADR-0009).
- **Integrações sempre atrás de adapter + DTO** (aqui a abstração é obrigatória, não opcional).

## Alternativas consideradas

### Clean Architecture literal (entities + use cases + interface adapters + frameworks)
Independência de framework que nunca será exercida (Laravel É a plataforma decidida); custo alto e permanente. Descartada.

### Repositories para tudo com interfaces
Passthrough burocrático (`$repo->find()` chamando `Model::find()`); mocks que testam o mock. Descartada — testes usam banco real (pasta 22), dispensando o principal argumento pró-mock.

### MVC solto (regra nos controllers/models gordos)
Vira espaguete no segundo ano. Descartada.

## Consequências

**Positivas:** princípios preservados onde pagam (fronteiras, transação única, testabilidade) sem imposto de indireção; código idiomático Laravel que qualquer dev do ecossistema entende.

**Negativas / dívidas:** a "dose" exige julgamento — mitigada por exemplos canônicos na pasta 05 e revisão dos agentes; risco de Services anêmicos que só repassam (mitigado: Service sem regra nem transação não deve existir).

**Gatilhos de revisão:** um módulo atingir complexidade que justifique domínio isolado do Eloquent (improvável; avaliar caso a caso com novo ADR).
