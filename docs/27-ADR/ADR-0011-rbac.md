# ADR-0011: RBAC com spatie/laravel-permission, deny-by-default

> **Status:** Aceito · **Data:** 2026-07-03 · **Decisores:** security-specialist
> **Módulos afetados:** 19, 18, 25

## Contexto

Poucos usuários com papéis bem definidos (dono, produção, vendas, expedição, financeiro, contador), exigências de alçada (descontos, ajustes) e segregação (contador só leitura fiscal — BR-803). Auditoria exige autor nominal com papel claro.

## Decisão

RBAC com **`spatie/laravel-permission`**: permissões granulares `modulo.acao`, papéis como conjuntos versionados por seeder, **negação por padrão** em toda rota (BR-801), Policies para nuances contextuais e testes de autorização obrigatórios por endpoint (matriz na pasta 19).

## Alternativas consideradas

### Gates/Policies artesanais sem pacote
Viável, mas reimplementa caching de permissões, sync de papéis e convenções que o pacote (maduro, mantido, onipresente) resolve. Descartada.

### ABAC/regras por atributo (Casbin etc.)
Flexibilidade que o cenário não pede; papéis cobrem o organograma real. Complexidade descartada — nuances pontuais ficam nas Policies.

### Permissões hardcoded por papel no código
Mudar acesso exigiria deploy; sem trilha. Descartada.

## Consequências

**Positivas:** modelo simples de raciocinar/testar; UI recebe a lista de permissões e se adapta; alçadas verificáveis.

**Negativas / dívidas:** papéis versionados exigem disciplina de seeder+auditoria em mudanças; risco de proliferação de permissões (mitigado: criar permissão nova exige atualizar pasta 19 no mesmo PR).

**Gatilhos de revisão:** multi-local com acesso segmentado por loja → adicionar dimensão de escopo (team feature do próprio pacote).
