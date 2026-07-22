# Visão C4

> **Status:** Aprovado · **Última atualização:** 2026-07-03 · **Responsável:** chief-architect

Diagramas nos níveis 1–3 do modelo C4. Nível 4 (código) é responsabilidade das pastas 05/06 e do próprio código.

## Nível 1 — Contexto

```mermaid
flowchart TB
    DONO["👤 Gestão (dono)"] --> ERP
    OPER["👤 Produção / Vendas / Expedição"] --> ERP
    CONT["👤 Contador (leitura fiscal)"] --> ERP
    CLIENTE["👤 Cliente final / lojista"] --> WOO
    ERP["🏢 ERP Dona Arteira<br/>Sistema mestre da operação"]
    WOO["🛍️ WordPress + WooCommerce<br/>E-commerce (canal)"]
    SEFAZ["🏛️ SEFAZ<br/>Autorização de NF-e"]
    ME["🚚 Melhor Envio / Transportadoras"]
    MAIL["✉️ E-mail (SMTP)"]
    ERP <-- "REST + webhooks<br/>produtos, estoque, pedidos, clientes" --> WOO
    ERP -- "NF-e (SOAP/XML, cert. A1)" --> SEFAZ
    ERP -- "etiquetas, rastreio" --> ME
    ERP -- "notificações, XML/DANFE" --> MAIL
```

## Nível 2 — Contêineres

```mermaid
flowchart TB
    subgraph host["Hospedagem (gestao.donaarteira.com.br)"]
        SPA["SPA React + Vite + TS<br/>build estático servido pelo web server"]
        API["Aplicação Laravel 12 (PHP 8.4)<br/>API REST /api/v1 + webhooks"]
        WORKER["Workers de fila<br/>(queue:work via cron/supervisor)"]
        SCHED["Scheduler<br/>(schedule:run a cada minuto)"]
        DB[(MariaDB<br/>utf8mb4)]
        FILES["Storage<br/>XMLs NF-e, DANFEs, uploads, certificado A1 (cifrado, fora do webroot)"]
    end
    SPA -- "HTTPS JSON<br/>Sanctum cookie" --> API
    API --> DB
    API --> FILES
    WORKER --> DB
    WORKER --> FILES
    SCHED --> WORKER
    WORKER <--> EXT["APIs externas<br/>Woo · SEFAZ · Melhor Envio"]
    EXT_WH["Webhooks Woo"] --> API
```

**Nota:** a viabilidade de `WORKER`/`SCHED` no plano Hostinger Business é a principal restrição aberta — ver [ADR-0016](../27-ADR/ADR-0016-hospedagem.md).

## Nível 3 — Componentes do contêiner Laravel

```mermaid
flowchart LR
    subgraph http["Http (por módulo)"]
        C[Controllers]
        FR[FormRequests]
        RES[API Resources]
    end
    subgraph modules["Modules/*"]
        SVC[Services / Actions]
        MDL[Models + invariantes]
        EVT[Events]
        POL[Policies]
    end
    subgraph infra["Infra compartilhada"]
        REPO[Repositories]
        JOBS[Jobs]
        INT["Integrations/*<br/>WooAdapter · SefazAdapter · MelhorEnvioAdapter"]
        MAP[IntegrationMappings]
        AUDITS[Auditoria]
    end
    C --> FR --> SVC
    SVC --> MDL --> EVT
    SVC --> REPO
    EVT --> JOBS --> INT --> MAP
    C --> RES
    POL --> C
    MDL --> AUDITS
```

Estrutura de pastas correspondente definida em [05-Backend](../05-Backend/README.md).
