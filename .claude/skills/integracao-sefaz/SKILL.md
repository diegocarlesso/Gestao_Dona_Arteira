---
name: integracao-sefaz
description: Implementa/evolui a comunicação com a SEFAZ para NF-e via sped-nfe e certificado A1 (transmissão, consulta, eventos, contingência) conforme a pasta 14. Use para qualquer trabalho no canal técnico fiscal — regras tributárias são da pasta 13/fiscal-specialist.
---

# Skill: Integração SEFAZ (NF-e)

## Objetivo
Canal técnico com a SEFAZ robusto e auditável: assinar, transmitir, consultar, eventos e contingência — **homologação primeiro, sempre** (BR-605).

## Pré-requisitos (bloqueantes)
1. Docs lidos: `docs/14-NFe/README.md` (fluxo, numeração, contingência) + perfis da `docs/13-Fiscal` validados pelo contador para o cenário em questão.
2. Pré-flight do ambiente: extensões PHP (openssl, soap, dom, curl), limites de execução — falhou = parar e acionar ADR-0016.
3. Certificado A1 instalado conforme docs/25 (fora do webroot, cifrado, senha só no .env); validade > 30 dias.
4. Ambiente de HOMOLOGAÇÃO configurado e testado antes de qualquer produção.

## Entradas
UF do emitente, série/ambiente, certificado, perfis fiscais, pedido a faturar.

## Fluxo
1. Implementar atrás de `NfeGatewayInterface` (ADR-0009 — troca por API gerenciada sem tocar o domínio).
2. Numeração: reserva via `fiscal_series` com `SELECT ... FOR UPDATE`; rejeição reaproveita o número; abandono → inutilização (BR-602).
3. Montagem do XML: dados do pedido (snapshot) + `tax_profiles`; validação local contra schema ANTES de transmitir.
4. Assinatura A1 → transmissão (job assíncrono, nunca inline na request) → tratamento do retorno: autorizada (salvar XML+protocolo ANTES de responder, gerar DANFE, evento `InvoiceAuthorized`), rejeitada (motivo traduzido em ação), timeout (retry; SEFAZ fora → contingência SVC manual com runbook).
5. Eventos: cancelamento (valida prazo/status — BR-604), CC-e, inutilização — cada um com XML de evento guardado.
6. Guarda: XML+eventos em storage com backup (BR-603, ≥ 5 anos); e-mail cliente/contador.
7. Testes: fixtures de retornos (autorizada, rejeições comuns 204/539/778, timeout); concorrência de numeração; bateria de 20 notas em homologação com cenários variados.

## Saídas
Gateway sped-nfe completo + eventos + contingência + guarda + runbooks RB-04/05 + testes.

## Critérios mínimos
Zero possibilidade de perder XML autorizado; zero furo/duplicata de numeração sob concorrência; nenhuma emissão em produção sem bateria de homologação verde.

## Checklist final
- [ ] Pré-flight de ambiente passou? Certificado validado e monitorado?
- [ ] Interface de gateway respeitada (domínio não conhece sped-nfe)?
- [ ] XML validado contra schema local antes da SEFAZ?
- [ ] Autorizada = imutável; correções só via eventos?
- [ ] Contingência chaveável com runbook e retransmissão testada?
- [ ] Rejeições traduzidas em mensagens acionáveis pt-BR?
