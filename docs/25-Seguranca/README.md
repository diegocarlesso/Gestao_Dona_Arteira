# 25 — Segurança

> **Status:** Aprovado · **Última atualização:** 2026-07-03 · **Responsável:** security-specialist
> **Regras:** BR-801…BR-804 · **Baseline:** OWASP ASVS nível 1 (nível 2 em fiscal/financeiro) · Skill relacionada: `/security-review` a cada gate

## 1. Objetivo

Segurança por padrão em um sistema que guarda dados pessoais de clientes, movimenta informação financeira e assina documentos fiscais com certificado digital. Este documento é o índice de controles; itens específicos vivem nos módulos.

## 2. Controles por camada

### Aplicação
- Autenticação Sanctum (cookie SameSite + CSRF para SPA; tokens com abilities mínimas para integrações) — pasta 18.
- Autorização deny-by-default em toda rota (pasta 19) + Policies contextuais; testes de 403 obrigatórios.
- Validação de entrada em FormRequests; saída via Resources explícitos (nunca serializar model inteiro).
- Uploads: só imagens (validação de MIME real), re-encode no servidor, nomes aleatórios, servidos sem execução.
- Proteções nativas ativas: Eloquent bindings (SQLi), escape (XSS) + CSP restritiva, rate limiting (pasta 07), headers (HSTS, X-Content-Type-Options, frame-ancestors 'none').
- Segredos: `.env` fora do webroot e fora do Git; credenciais de integração cifradas no banco (cast encrypted); rotação documentada por integração.

### Certificado A1 (ativo mais sensível)
Arquivo fora do webroot, cifrado em repouso, senha apenas no `.env`; acesso somente pelo serviço de assinatura; **nunca** em backup não cifrado, log ou repositório; monitor de validade (pasta 24); runbook de renovação anual e de revogação em caso de suspeita de vazamento.

### Infraestrutura
HTTPS obrigatório (redirect + HSTS) · SSH somente com chave · WAF/proteções do painel Hostinger ativas · banco inacessível externamente (bind local/allowlist) · staging com dados anonimizados e auth básica extra · dependências auditadas no CI (`composer audit`/`npm audit`) + atualização mensal agendada.

## 3. LGPD

| Tema | Prática |
|---|---|
| Inventário de dados pessoais | clientes (nome, doc, endereço, e-mail, telefone) e usuários; finalidades: contrato/venda, obrigação fiscal — inventário mantido aqui |
| Base legal | execução de contrato + obrigação legal (fiscal); marketing NÃO é finalidade do ERP |
| Minimização | ERP não coleta além do necessário para venda/NF-e; contador vê só fiscal (BR-803) |
| Direitos do titular | processo manual documentado: exportar dados do cliente, retificar, anonimizar (quando não houver dever legal de guarda — NF-e emitida mantém dados por obrigação fiscal) |
| Anonimização | rotina para clientes inativos sem pendência fiscal (nome → "Cliente anonimizado", doc/e-mail/telefone apagados; pedidos preservam valores) |
| Logs/auditoria | dados pessoais mascarados em logs; auditoria retém o mínimo identificável necessário |
| Incidente | runbook de resposta: conter, avaliar dados afetados, comunicar titulares/ANPD se risco relevante, registrar |

## 4. Checklist por gate (bloqueante)

- [ ] Rotas novas com permissão + teste 403 · [ ] segredos fora do código · [ ] inputs validados · [ ] nada sensível em log · [ ] dependências sem vulnerabilidade alta · [ ] `/security-review` executado e achados tratados.

## 5. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| WordPress comprometido pivotar para o ERP | Média | Alto | Sistemas isolados (só API com chaves próprias); chaves Woo com escopo mínimo; nunca credencial compartilhada |
| Vazamento do certificado A1 | Baixa | Crítico | controles acima + revogação imediata (runbook) |
| Shared hosting = vizinhança barulhenta | Média | Médio | mais um argumento do ADR-0016 (VPS isola) |
| Senha fraca de operador | Média | Médio | política de senha + 2FA nos papéis sensíveis (BR-804) |

## 6. Evoluções futuras

- Pentest externo leve antes do Gate 05 (fiscal) — orçar · WebAuthn/passkeys (fase 7) · SIEM simples se equipe crescer.
