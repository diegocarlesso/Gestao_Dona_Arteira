# 14 — NF-e (Emissão Eletrônica)

> **Status:** Em revisão · **Última atualização:** 2026-08-10 · **Responsável:** nfe-specialist
> **Regras:** BR-601…BR-606 · **Fase:** Gate 05 · **ADR:** [0009 (sped-nfe vs API gerenciada)](../27-ADR/ADR-0009-emissao-nfe.md)

## 1. Objetivo

Emitir, gerir e guardar NF-e modelo 55 com certificado A1: do pedido faturado ao XML autorizado + DANFE entregues ao cliente e ao contador — com contingência, eventos (cancelamento, CC-e, inutilização) e guarda legal de 5 anos.

## 2. Responsabilidades

- **Faz:** montagem do XML a partir de pedido + perfis fiscais (pasta 13), assinatura (A1), transmissão/consulta SEFAZ, DANFE PDF, eventos, numeração por série, guarda e distribuição (e-mail cliente/contador).
- **Não faz:** decidir regra tributária (pasta 13), cobrar (Financeiro), expedir (Vendas — que depende daqui via BR-309).

## 3. Fluxo de emissão

> Gatilho automático (pedido confirmado do site → emissão), organização do
> módulo (`Fiscal` único, perfis + emissão) e o gate de produção (flag
> homologação/produção até validação do contador) estão desenhados no
> [ADR-0025](../27-ADR/ADR-0025-emissao-automatica-nfe.md).

```mermaid
flowchart TD
    A[Pedido pronto p/ faturar] --> B[Pré-validação:<br/>cliente com doc BR-001, endereço,<br/>produtos com dados fiscais BR-606]
    B --> C[Reservar número<br/>fiscal_series com lock BR-602]
    C --> D[Montar XML<br/>tax_profiles + snapshot do pedido]
    D --> E[Assinar com A1<br/>certificado cifrado fora do webroot]
    E --> F[Transmitir SEFAZ<br/>sped-nfe]
    F --> G{Retorno}
    G -- autorizada --> H[Salvar XML+protocolo<br/>gerar DANFE<br/>evento InvoiceAuthorized]
    G -- rejeitada --> I[Status rejected + motivo claro<br/>corrigir e reemitir MESMO número]
    G -- timeout/SEFAZ fora --> J{Urgente?}
    J -- sim --> K[Contingência SVC<br/>tpEmis=6/7 conforme UF]
    J -- não --> L[Fila re-tenta<br/>número permanece reservado]
    H --> M[E-mail cliente: XML+DANFE<br/>cópia mensal p/ contador]
```

### 3.1 O que já existe em código (2026-08-10)

| Etapa do fluxo | Onde | Estado |
|---|---|---|
| Pré-validação + numeração | `Fiscal\Services\BuildInvoiceFromOrder` | ✅ em produção (inerte) |
| Montagem do XML 4.00 | `Fiscal\Services\Gateways\MontarXmlNfe` | ✅ testado contra o XSD oficial |
| Certificado A1 + `Tools` | `Fiscal\Services\Gateways\CanalSefaz` | ✅ escrito; assinatura verificada com certificado autoassinado |
| Assinatura + transmissão + retorno | `Fiscal\Services\Gateways\SpedNfeGateway` | ⚠️ escrito, **não verificado** (sem A1 real) |
| DANFE, eventos, contingência, guarda em lote | — | ❌ não iniciados |

**O bind ativo continua sendo `NullNfeGateway`** (`FiscalServiceProvider`).
Trocar por `SpedNfeGateway` é uma linha, e só depois da bateria em
homologação que a BR-605 exige.

Três coisas impedem a primeira emissão de verdade, nenhuma delas de código:

1. **Certificado A1** — `NFE_CERT_PATH`/`NFE_CERT_PASSWORD` (pasta 25).
2. **Dados do emitente e CST de PIS/COFINS** — `NFE_EMITENTE_*`,
   `NFE_PIS_CST`, `NFE_COFINS_CST`, todos vazios até a doc 13 sair de
   bloqueada. A emissão recusa nomeando a variável que falta; não chuta.
3. **Código IBGE do município do destinatário** — `enderDest/cMun` é
   obrigatório no layout e `customer_addresses` **não guarda esse campo**.
   O contrato já o prevê (`Sales\DTO\OrderInvoiceAddress::$cityCode`), mas
   chega sempre nulo. É pendência do cadastro de Vendas (docs/10), e a
   emissão para com mensagem própria em vez de aproximar pelo nome da
   cidade — nota autorizada com município errado ninguém percebe.

Pontos críticos:

- **Numeração** (BR-602): `fiscal_series.next_number` com `SELECT ... FOR UPDATE`; rejeição **não** queima número (reemite com o mesmo); número abandonado → inutilização na SEFAZ.
- **Imutabilidade**: autorizada nunca muda; correção → CC-e (erros permitidos) ou cancelamento (BR-604, prazo 24 h sem circulação) ou devolução.
- **Homologação primeiro** (BR-605): ambiente 2 permanente, cliente "NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO..." — toda mudança fiscal é testada lá antes.
- **Contingência SVC**: chaveamento manual (decisão do operador) com runbook próprio (pasta 24); notas em contingência são retransmitidas quando a SEFAZ normaliza.

## 4. Certificado A1 (segurança — detalha na pasta 25)

Arquivo `.pfx` armazenado **fora do webroot**, cifrado (senha no cofre de configuração, nunca no repositório), permissão restrita. **Monitorar validade**: alertas 30/15/7 dias antes do vencimento (health check — pasta 24). Renovação anual é operação planejada com runbook.

## 5. Guarda e distribuição (BR-603)

XML autorizado + eventos: storage da aplicação com backup diário + cópia mensal exportada (zip por competência) para storage externo. Retenção ≥ 5 anos. DANFE é re-gerável a partir do XML (não precisa da mesma garantia). Painel fiscal: busca por chave/número/cliente/período com download em lote (perfil do contador — BR-803).

## 6. Dependências

| Depende de | Motivo |
|---|---|
| 13-Fiscal | perfis tributários validados pelo contador |
| Certificado A1 válido | assinatura |
| `nfephp-org/sped-nfe` | biblioteca de emissão (ADR-0009). ✅ **Instalada em 2026-08-10** (`^5.2`, v5.2.8) — sem conflito com a árvore existente; entram junto `sped-common`, `sped-gtin` e `justinrainbow/json-schema` |
| Extensões PHP: openssl, soap, curl, dom | ✅ verificadas nos dois SAPIs em 2026-08-10 (ver §8). `ext-soap` é exigida pelo `composer.json` da lib; o job `lint` do CI passou a instalá-la junto com `zip` para o `composer install` não reprovar |
| Vendas | dados do pedido; expedição espera autorização (BR-309) |

## 7. Boas práticas

- Toda mensagem de rejeição SEFAZ é traduzida para ação clara no ERP ("Rejeição 778: NCM inexistente — corrija o NCM do produto X").
- Emissão é **assíncrona** (job) com feedback em tela via polling/refresh — nunca travar a UI esperando SEFAZ.
- Log completo de request/response SOAP (sem dados do certificado) por 90 dias para suporte.
- Dry-run mensal em homologação como teste de fumaça do canal fiscal.

## 8. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Shared hosting sem extensão/CPU para assinar+transmitir | ✅ **Validado em 2026-08-10**: `openssl`, `soap`, `curl`, `dom`, `simplexml`, `zip`, `mbstring`, `bcmath`, `gd` presentes nos dois SAPIs (CLI **e** LiteSpeed/web — checados separadamente, é onde o P-16 mordeu antes). CPU sob carga real de assinatura+transmissão ainda não testada | — | ADR-0016 (VPS) continua a mitigação se a carga real não couber |
| NTs da reforma tributária exigirem atualização rápida da lib | Alta | Alto | Acompanhar releases sped-nfe; plano B = API fiscal gerenciada (ADR-0009, gatilhos definidos) |
| Perda de XML | Baixa | Crítico (multa) | Guarda redundante + teste de restore trimestral |
| Certificado vencer sem aviso | Média | Crítico | Alertas automatizados 30/15/7 dias |

## 9. Evoluções futuras

- NFC-e se abrir varejo presencial com volume (novo ADR).
- Importação de NF-e de fornecedores + manifestação (fase 6).
- CT-e/MDF-e apenas se logística própria surgir (improvável).
