# Análise Crítica — Gate 00

> **Status:** Aprovado · **Última atualização:** 2026-07-03 · **Responsável:** chief-architect
> Revisão honesta da fundação: pontos fracos, riscos, decisões questionáveis e o que o dono precisa decidir. Este documento **não** existe para validar o que foi feito — existe para atacá-lo enquanto é barato mudar.

## 1. Como ler este documento

A fundação foi construída com qualidade de ERP comercial, como pedido. Mas "profissional" não é sinônimo de "complexo", e várias decisões pedidas no enunciado merecem ser questionadas antes de virarem código. Abaixo, separo: (A) a tensão central do projeto, (B) decisões que questiono, (C) riscos futuros, (D) pontos fracos da própria documentação, (E) documentos adicionais propostos e (F) as decisões que travam o Gate 01 e dependem do dono.

## 2. (A) A tensão central: ambição de arquitetura × bus factor 1

O fato mais importante do projeto, e que atravessa tudo: **é um ERP de escopo empresarial construído e mantido por essencialmente uma pessoa, para um negócio artesanal de pequeno porte.** Isso cria uma tensão permanente:

- O enunciado pede Clean Architecture, DDD, SOLID, Repository Pattern, API First, SPA separada, testes, observabilidade, auditoria — o arsenal de um ERP de time grande.
- O contexto real é: 1 desenvolvedor, hospedagem barata, centenas de pedidos/mês, produção de peças de gesso.

Cada padrão adotado sem dose corre o risco de virar imposto de manutenção que **a mesma pessoa** paga toda semana. Por isso, ao longo da fundação, apliquei uma filtragem deliberada — documentada nos ADRs — em vez de aceitar os padrões cegamente:

| Pedido no enunciado | Como foi dosado | ADR |
|---|---|---|
| Clean Architecture | Camadas e fronteiras SIM; independência de framework NÃO (Laravel é a plataforma) | 0015 |
| Repository Pattern | Só onde agrega; CRUD trivial usa Eloquent direto | 0015 |
| DDD | Estratégico completo; tático só nos módulos ricos; sem CQRS/Event Sourcing | 0001 |
| Microserviços (implícito em "desacoplado/escalável") | Monolito modular; extração só com gatilho | 0001 |

**Recomendação honesta ao dono:** a maior ameaça ao projeto não é escolher a tecnologia errada — é **não terminar**. A disciplina que protege contra isso é: escopo por gates bloqueantes (pasta 28), simplicidade militante (a solução mais simples que atende, sempre) e automação (CI, agentes, skills) para compensar a falta de braços. Se em algum momento a "pureza arquitetural" brigar com "entregar o Gate", entregar o Gate vence.

## 3. (B) Decisões que eu questiono (não aceite só porque foram pedidas)

### B1. SPA React separada vs. abordagem integrada — a que mais questiono
**Decisão registrada:** SPA React + API REST separada (ADR-0004).
**A tensão:** para 1 dev, manter duas bases de código (backend PHP + frontend TS) com contrato OpenAPI no meio é *mais* trabalho do que uma stack integrada (Laravel + Inertia + React), que daria React no frontend **sem** manter uma API pública e um client gerado.
**Por que mantive assim mesmo:** a API REST vai ter que existir de qualquer forma para WooCommerce, futuro mobile e marketplaces (BR-701, ADR-0003). Com Inertia, você acabaria mantendo *duas* superfícies (as telas Inertia + a API para integrações) — pior. A SPA dá dogfooding do contrato desde o dia 1.
**O que o dono precisa saber:** essa é a decisão de maior custo recorrente para um solo dev. Ela se paga **se e somente se** as integrações e o mobile realmente acontecerem. Se o dono decidir que o e-commerce (Woo) é o único canal digital para sempre e não haverá mobile, uma stack integrada seria defensável e mais rápida. **Vale reconfirmar essa premissa antes do Gate 01.**

### B2. Emissão de NF-e própria durante a reforma tributária
**Decisão:** emitir com sped-nfe local (ADR-0009). **Questiono:** manter um emissor fiscal próprio em 2026+ significa perseguir cada Nota Técnica da reforma (IBS/CBS) — trabalho fiscal contínuo que compete com o resto do ERP, feito por quem não é especialista fiscal. Uma **API fiscal gerenciada** (~R$100–300/mês) transferiria esse fardo. Deixei o módulo atrás de uma interface (`NfeGatewayInterface`) justamente para permitir a troca sem retrabalho, e registrei gatilhos objetivos de migração. **Recomendação:** reavaliar essa escolha explicitamente no Gate 05 com o custo real na mesa — a decisão "de graça" pode sair cara em horas.

### B3. Hospedagem compartilhada (a decisão mais urgente) — ver seção F.

### B4. Escopo de 7 gates para um solo dev
**Questiono o realismo do todo.** Produção + estoque + vendas + compras + financeiro + fiscal + integrações é, honestamente, anos de trabalho para uma pessoa. O roadmap (pasta 28) mitiga isso com gates bloqueantes e valor entregue cedo (já no Gate 02 o negócio roda melhor que hoje). Mas o dono deve encarar que **os gates 4–6 podem levar muito mais tempo que o imaginado**, e que está tudo bem operar por um bom período só com os gates 1–3 (o núcleo). O financeiro/fiscal pode continuar no contador/planilha enquanto o núcleo amadurece.

### B5. Migrar de dois sistemas, não um
O enunciado fala em migrar do WooCommerce, mas o levantamento do legado (docs/01/02) mostra que **o desktop tem dados que o Woo não tem** (clientes de atacado, preços de atacado). A migração real tem **duas fontes** — reconhecido no ADR-0010 e na pasta 17. Isso é mais trabalho do que o enunciado sugeria e precisa de acesso ao banco MySQL do desktop (pendência).

## 4. (C) Riscos futuros (além dos já tabelados em cada módulo)

| # | Risco | Onde mora | Severidade | Mitigação atual / proposta |
|---|---|---|---|---|
| R1 | **Hospedagem inadequada** compromete filas, NF-e, backups | ADR-0016 | 🔴 Crítica | Decisão do dono (seção F1); recomendação = VPS |
| R2 | **Documentação escrita contra um sistema imaginado** diverge da realidade quando o código chegar | toda a fundação | 🟠 Alta | Muitos docs marcados "Em revisão/Rascunho" e dependentes de entrevistas (pasta 30) e do inventário (pasta 17); revisão obrigatória de fim de gate |
| R3 | **Regras de produção são hipóteses** (BR-1xx) — o módulo core pode nascer irreal | pasta 08/30 | 🟠 Alta | Entrevistas são pré-requisito formal do Gate 03; etapas configuráveis |
| R4 | **Validação fiscal ausente** — CFOP/NCM/CSOSN são chutes até o contador confirmar | pasta 13 | 🟠 Alta | Reunião com contador é pré-requisito bloqueante do Gate 05; toda BR-6xx nasce 💡 |
| R5 | **Reforma tributária** muda o layout da NF-e durante o projeto | pasta 13/14 | 🟠 Alta | Perfis versionados por vigência; gatilho de troca para API gerenciada |
| R6 | **Fadiga de solo dev / abandono** — o maior risco real de qualquer projeto assim | projeto | 🟠 Alta | Escopo em gates, automação (agentes/skills), docs que permitem retomar após pausas |
| R7 | **Dados sujos do Woo** contaminam o ERP | pasta 17 | 🟡 Média | Fase de saneamento + validação com aprovação humana; inventário mede antes |
| R8 | **Equipe continua editando no wp-admin** pós-cutover, corrompendo o SSOT | pasta 16 | 🟡 Média | BR-702 + reconciliação com alerta nominal; mini-plugin de aviso no wp-admin |
| R9 | **Oversell em pico sazonal** (Natal) | pasta 09 | 🟡 Média | Reserva via webhook + buffer + sync<2min + reconciliação; proibição de cutover em nov/dez |
| R10 | **Certificado A1 vence sem aviso** → para de faturar | pasta 14/25 | 🟡 Média | Alertas 30/15/7 dias no health check |
| R11 | **Backup nunca testado** = sem backup | pasta 23 | 🟡 Média | Teste de restore trimestral obrigatório (runbook) |
| R12 | **Custo mensal cresce** (VPS + API fiscal + storage + e-mail) sem o dono ter mapeado | financeiro do projeto | 🟡 Média | **Documento de custos proposto** (seção E) |

## 5. (D) Pontos fracos da própria fundação (auto-crítica)

1. **Volume alto de "Em revisão/Rascunho".** Vários docs de módulo (produção, fiscal, vendas, compras) dependem de informação que ainda não temos (entrevistas, contador, inventário). Isso é **correto e honesto** — melhor um doc marcado como hipótese do que uma falsa certeza — mas significa que a fundação **não está "pronta"**; ela está pronta *para começar*, com pendências explícitas.
2. **Modelo de dados é conceitual, não físico.** A pasta 04 descreve tabelas prováveis; variações de produto, locais de estoque e o modelo fiscal fino só se fecham com dados reais no Gate 01. Não tente congelar o esquema agora.
3. **NFRs com números estimados (📏).** Metas de latência/volume são palpites até o inventário rodar. Recalibrar é parte do Gate 01, não sinal de erro.
4. **OpenAPI ainda não existe como arquivo.** A pasta 07 define o *processo* e a estrutura, mas o spec em si nasce no Gate 01 (seria código escrever agora). Coerente com "só documentação", mas registro que é um artefato pendente, não esquecido.
5. **Runbooks referenciados, não escritos.** As pastas 23/24 citam RB-01..09; eles nascem quando cada capacidade existir. Os *templates* existem; os runbooks preenchidos são trabalho de gate.
6. **Dependência de conhecimento externo não resolvida na fundação.** Contador, operação e volumes reais são entradas que a documentação *pede* mas não *tem*. A fundação é, em parte, uma lista estruturada de boas perguntas.

Nenhum desses pontos é corrigível "escrevendo mais doc agora" — todos dependem de informação do mundo real. Tentar resolvê-los no papel produziria ficção. A resposta certa é executá-los como pré-requisitos dos gates, como está no roadmap.

## 6. (E) Documentos adicionais propostos

Identifiquei lacunas que valem documento próprio (a criar quando a fase chegar — não agora, para não inflar a fundação com mais hipóteses):

| Documento proposto | Onde | Quando | Por quê |
|---|---|---|---|
| **Modelo de custos do projeto** | 00-Visao-Geral/05 | antes do Gate 01 | Dono está pagando: VPS, certificado A1, storage de backup, SMTP transacional, possível API fiscal. Consolidar o custo mensal/anual evita surpresa e informa B2/F1. |
| **Guia de setup do desenvolvedor** | 05-Backend/01 | início do Gate 01 | Reduz o custo de retomar após pausas (mitiga R6); ambiente Docker reproduzível. |
| **Registro de Operações de Tratamento (ROPA) LGPD** | 25-Seguranca/01 | Gate 01 | Formaliza o inventário de dados pessoais da §3 da pasta 25 como artefato de conformidade. |
| **Estratégia de dados de teste/anonimização** | 22-Testes/01 | Gate 01 | Staging usa "dados anonimizados" (pasta 23) — falta definir *como* anonimizar dumps de produção. |
| **Plano de capacidade pós-inventário** | 03-Arquitetura/03 | fim do Gate 01 | Substitui os 📏 por números reais e valida (ou não) as escolhas de fila/hospedagem. |
| **Decisões pendentes (tracker vivo)** | este documento §F | contínuo | Já embutido aqui; se crescer, vira doc próprio. |

## 7. (F) Decisões que dependem do dono (bloqueiam ou moldam o Gate 01)

Estas são as perguntas que **a documentação não pode responder sozinha**. Estão listadas por urgência.

### F1. ✅ ~~Hospedagem: VPS ou permanecer no plano Business?~~ — **decidido em 2026-07-22: plano Business**
O dono optou pelo **Plano B do [ADR-0016](../27-ADR/ADR-0016-hospedagem.md)** — permanecer no plano Business já contratado, contrariando a recomendação técnica. As dívidas operacionais (fila por cron, sem Redis, RPO 24 h, risco de ambiente para NF-e) foram assumidas conscientemente e os gatilhos de reabertura estão ativos.

**Consequência que passou a valer imediatamente:** como o escopo contratado é o completo (inclui NF-e no Gate 05), a validação de extensões e limites do ambiente foi antecipada de "antes do Gate 05" para **a semana 1 do Gate 01** — [23-Deploy/01](../23-Deploy/01-validacao-ambiente-business.md). Se o plano não suportar `soap`, `openssl` ou saída HTTPS para a SEFAZ, o ADR-0016 reabre antes de qualquer código.

### F2. 🟠 Reunião de validação fiscal com o contador (pasta 13)
Regime do Simples, CFOPs, NCMs, CSOSN, obrigações. Bloqueia o Gate 05, mas as respostas influenciam o modelo de dados desde cedo. **Agendar já** — é a dependência externa de maior lead time.

### F3. 🟠 Acessos técnicos a obter
- Chaves REST do WooCommerce (read/write) + capacidade de criar webhooks.
- Dump/acesso somente-leitura ao banco MySQL do sistema desktop legado.
- Inventário dos plugins ativos no WordPress (checkout BR, frete, rastreio) — pasta 16/01.
Sem isso, migração e sincronização não começam.

### F4. ✅ ~~Reconfirmar a premissa da SPA separada (B1)~~ — **decidido em 2026-07-22: Inertia**
A premissa não resistiu à reconfirmação. O ADR-0004 apoiava-se em *"a API REST vai ter que existir de qualquer forma para o WooCommerce"* — mas a integração Woo precisa de chamadas de saída e de poucos receptores de webhook, não de uma API REST completa sob contrato OpenAPI. Nenhum consumidor adicional (mobile, marketplace) foi confirmado.

Decisão: **Laravel + Inertia + React** ([ADR-0019](../27-ADR/ADR-0019-inertia-substitui-spa.md), que substitui o [ADR-0004](../27-ADR/ADR-0004-spa-react-separada.md)). Economia estimada de 200–350 h ao longo dos Gates 01–06 e ataque direto ao risco R6 (fadiga de solo dev). Custo: as pastas 06, 07, 23, 03, 05, 22 e 25 precisam de revisão antes do código.

### F5. 🟡 Regras de negócio que só o dono/operação definem
- **BR-301:** critério do preço de atacado (cliente marcado? quantidade mínima?).
- **BR-305:** limite de desconto sem aprovação.
- **BR-204:** buffer de estoque padrão para o site.
- **BR-503:** plano de categorias financeiras inicial.
Nenhuma bloqueia o começo, mas todas são entrada dos Gates 02–04.

### F6. ✅ ~~Inicializar o repositório Git~~ — **resolvido em 2026-07-22**
A fundação foi versionada (`git init` + commit inicial em `main`). `docs/database_dump/` ficou **deliberadamente fora** do versionamento: 115 MB (99,5% do peso do projeto) contendo dados pessoais reais de clientes — versionar criaria passivo de LGPD irreversível no histórico. O dump permanece local; sua análise está na [pasta 31](../31-Inventario-Legado/README.md).

## 8. Veredito

A fundação é sólida, honesta sobre o que não sabe, e **dosada para o porte real** — não é um ERP de manual copiado sem crítica. Ela está pronta para orientar anos de desenvolvimento, **desde que**: (1) o dono tome a decisão de hospedagem (F1), (2) as dependências externas (contador, acessos, entrevistas) sejam tratadas como pré-requisitos de gate e não como detalhes, e (3) o projeto resista à tentação de sofisticar antes de entregar o núcleo. O maior risco não está em nenhuma decisão técnica isolada — está em subestimar o esforço total para uma pessoa. Os gates existem para transformar esse maratona em uma sequência de vitórias utilizáveis.

O próximo passo não é escrever mais documentação. É o dono responder à seção F e, com F1 e F3 resolvidos, iniciar o **Gate 01**.
