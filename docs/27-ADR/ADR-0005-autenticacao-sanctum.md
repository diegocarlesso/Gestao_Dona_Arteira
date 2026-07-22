# ADR-0005: Autenticação — Laravel Sanctum (cookie SPA + tokens de integração)

> **Status:** ✅ Aceito — **parcialmente revisto pelo [ADR-0019](ADR-0019-inertia-substitui-spa.md)** (2026-07-22) · **Data:** 2026-07-03 · **Decisores:** security-specialist, chief-architect
> **Módulos afetados:** 18, 25, 07
> ⚠️ Usuários humanos passam a autenticar por **sessão nativa do Laravel** (Inertia); o modo "cookie SPA" do Sanctum deixa de ser necessário. Os **tokens de integração** via Sanctum, o 2FA e o RBAC permanecem exatamente como decidido aqui.

## Contexto

Dois tipos de consumidores: a SPA no mesmo domínio (usuários humanos, sessões) e clientes máquina (scripts de migração, futuras integrações/mobile). Requisitos: 2FA para papéis sensíveis, revogação imediata, mínima superfície de ataque.

## Decisão

**Sanctum** em dois modos: (a) SPA por **cookie de sessão** (SameSite=Lax, Secure, HttpOnly) com CSRF — nada de token em localStorage; (b) **personal access tokens** com abilities mínimas e expiração para consumidores máquina. 2FA TOTP para admin/finance (BR-804).

## Alternativas consideradas

### Laravel Passport (OAuth2 completo)
Servidor OAuth só se justificaria com apps de terceiros autorizando em nosso nome — inexistente no horizonte. Complexidade sem uso. Descartada (pode ser adotado depois sem quebrar Sanctum).

### JWT stateless (firebase/php-jwt etc.)
Revogação imediata difícil (blacklists), refresh tokens artesanais, token no storage do browser vulnerável a XSS. Para ERP interno, sessões server-side são mais seguras e simples. Descartada.

## Consequências

**Positivas:** revogação instantânea (suspender usuário mata a sessão), XSS não rouba credencial da SPA, tokens de máquina com escopo e auditoria.

**Negativas / dívidas:** cookie exige mesmo domínio raiz (ok: tudo em `donaarteira.com.br`); mobile futuro usará tokens (já suportado).

**Gatilhos de revisão:** app de terceiros precisando de OAuth → adicionar Passport ao lado; SSO corporativo (improvável).
