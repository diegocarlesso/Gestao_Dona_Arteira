# ADR-0009: Emissão de NF-e via sped-nfe com certificado A1 local

> **Status:** Aceito **com gatilhos de revisão explícitos** · **Data:** 2026-07-03 · **Decisores:** dono (custo), nfe-specialist
> **Módulos afetados:** 13, 14, 25

## Contexto

O projeto define certificado A1 e emissão própria. Volume estimado ≤ 500 notas/mês. A alternativa de mercado são APIs fiscais gerenciadas (Focus NFe, NFe.io, Tecnospeed…) que abstraem SEFAZ por mensalidade (~R$ 100–300/mês). A reforma tributária (IBS/CBS) está mudando o layout da NF-e ao longo de 2026+ via Notas Técnicas sucessivas — quem emite por conta própria precisa acompanhar cada NT.

## Decisão

Emitir com **`nfephp-org/sped-nfe`** (biblioteca open source consolidada) + certificado A1 no servidor (controles da pasta 25), arquitetura da pasta 14. **Condicionantes:** ambiente com extensões validadas antes do Gate 05 (pré-flight) e acompanhamento ativo de NTs/releases da lib como rotina mensal do gate fiscal em diante.

## Alternativas consideradas

### API fiscal gerenciada (Focus NFe ou similar)
**Prós fortes:** NTs da reforma viram problema do fornecedor; contingência, guarda e webhooks prontos; menos código fiscal para 1 dev manter. **Contras:** custo mensal perpétuo; lock-in; dados fiscais transitando por terceiro; latência extra. Não escolhida agora **por decisão de custo do projeto**, mas mapeada como plano B de implementação barata (o módulo Fiscal fala com uma interface `NfeGatewayInterface` — trocar sped-nfe por adapter HTTP de terceiro não toca o domínio).

### Emissor gratuito externo (ex.: emissores de secretarias/soluções desktop)
Sem integração — retrabalho manual permanente contra o objetivo do ERP. Descartada.

## Consequências

**Positivas:** custo zero de licença; controle total do fluxo; XML/certificado nunca saem da infraestrutura.

**Negativas / dívidas assumidas:** manutenção fiscal contínua é nossa (NTs, esquemas, reforma tributária); exige homologação disciplinada; risco concentrado no ambiente de hospedagem (ver ADR-0016).

**Gatilhos de revisão (mudar para API gerenciada se QUALQUER um ocorrer):**
- NT da reforma exigir retrabalho > 2 semanas-pessoa em um semestre.
- Duas ocorrências de notas atrasadas por problema de ambiente/lib em produção.
- Ambiente definitivo (ADR-0016) não suportar extensões/carga de assinatura com folga.
