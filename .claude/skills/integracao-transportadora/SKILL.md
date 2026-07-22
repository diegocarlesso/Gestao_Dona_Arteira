---
name: integracao-transportadora
description: Integra uma transportadora direta (fora do Melhor Envio) ao fluxo de expedição — cotação/tabela, despacho e rastreio — pelo framework da pasta 15. Use quando a empresa fechar contrato com transportadora específica.
---

# Skill: Integração Transportadora

## Objetivo
Plugar uma transportadora no MESMO fluxo de expedição existente (cotar → despachar → rastrear), sem criar caminho paralelo: a expedição não sabe qual transportadora está atrás da interface.

## Pré-requisitos
1. Template de integração preenchido (`docs/_templates/TEMPLATE-INTEGRACAO.md`) com: API/versão, autenticação, ambientes, limites, contatos do suporte — salvo em `docs/15-Integracoes/`.
2. Contrato interno respeitado: implementar a MESMA interface de frete usada pelo Melhor Envio (`FreightCarrierInterface`: cotar, despachar, rastrear) — se a interface ainda não existe, extraí-la é a primeira tarefa.
3. Transportadora cadastrada em `carriers` com tipo de integração.
4. Homologação/sandbox da transportadora (ou plano de testes com despachos reais de baixo valor documentado).

## Entradas
Credenciais, tabela de frete/contrato, formatos (API REST/SOAP/arquivo), campos exigidos (CNPJ, IE, volumes).

## Fluxo
1. `Integrations/<Transportadora>/` com Client + DTOs + adapter implementando a interface comum.
2. Cotação: por API se houver; senão tabela de frete importada (peso×faixa CEP) com vigência — atualizável sem deploy.
3. Despacho: minuta/coleta conforme o processo da transportadora; número de coleta vinculado ao shipment; documentos (etiqueta/romaneio) armazenados.
4. Rastreio: API/polling → timeline do pedido → cliente/Woo (mesmos eventos das demais).
5. Regras específicas (cubagem, valor declarado, frete mínimo) parametrizadas na integração — NUNCA hardcoded no módulo Sales.
6. Falha da transportadora → fluxo manual + alerta (BR-705).
7. Testes com fixtures; teste da interface comum (a expedição funciona igual com qualquer carrier).

## Saídas
Adapter da transportadora + doc preenchida + tabela de frete versionada + testes.

## Critérios mínimos
Expedição intocada (só uma opção nova de carrier); cotação divergente da fatura da transportadora ≤ tolerância definida; rastreio fluindo.

## Checklist final
- [ ] Interface comum implementada (zero `if transportadora` fora do adapter)?
- [ ] Tabela de frete com vigência e atualização sem deploy?
- [ ] Cubagem/mínimos parametrizados e testados com casos reais?
- [ ] Rastreio integrado à mesma timeline/eventos?
- [ ] Runbook de falha + fluxo manual documentados?
