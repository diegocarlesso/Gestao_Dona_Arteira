# 26 — Auditoria

> **Status:** 🟡 **Ligada** — trilha de mutações e `security_events` ativos; a tela de consulta segue pendente · **Última atualização:** 2026-07-24 · **Responsável:** security-specialist
> **Regras:** BR-802 · **ADR:** [0012 (laravel-auditing)](../27-ADR/ADR-0012-auditoria.md) · **Fase:** Gate 01 (transversal desde o início)
> **Código:** `config/audit.php` · `database/migrations/*_create_audits_table.php` · `*_create_security_events_table.php` · `app/Modules/Identity/{Enums/SecurityEventType,Models/SecurityEvent,Services/RecordSecurityEvent,Listeners}` · `tests/Feature/Identity/`

## 1. Objetivo

Responder com evidência a qualquer "quem mudou isso, quando e de que valor para qual?" — exigência de um sistema com estoque, dinheiro e documentos fiscais. Auditoria completa é princípio do projeto.

## 2. O que é auditado

| Categoria | Eventos | Mecanismo |
|---|---|---|
| Mutações de dados de negócio | create/update/delete/restore de produtos, preços, clientes, pedidos, títulos, OPs, configurações | `owen-it/laravel-auditing` → tabela `audits` (old/new JSON, user, ip, user_agent) |
| Fatos de domínio | eventos do catálogo da [pasta 02](../02-Dominio/01-eventos-de-dominio.md) (transições de pedido, movimentos, emissões) | tabelas próprias dos módulos (`order_status_history`, `inventory_movements`, `fiscal_document_events`) — o fato JÁ É a auditoria |
| Segurança | login ok/falha, logout, troca de senha, 2FA, `PermissionDenied`, criação/suspensão de usuário, mudança de papel | canal dedicado `security_events` |
| Ações sensíveis (BR-802) | ajuste de estoque, cancelamento de NF-e, estorno financeiro, mudança de preço em massa, reprocesso de integração | auditoria + **motivo obrigatório** digitado pelo autor |

### 2.1 O canal `security_events` (implementado em 2026-07-24)

**Por que não usar a tabela `audits`.** O `laravel-auditing` registra o
diff de um *model*: precisa de um registro que mudou. Os fatos de
segurança mais importantes não têm model nenhum — um login que falhou
com e-mail inexistente não muda linha alguma, e é exatamente o que se
quer ver quando alguém tenta entrar à força. Forçar esses fatos na
`audits` significaria inventar um auditável fictício.

Tabela `security_events`, seguindo as convenções da
[pasta 04](../04-Banco-de-Dados/02-convencoes-de-banco.md):

| Coluna | Tipo | Por quê |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `event` | VARCHAR(64) | Enum PHP `SecurityEventType`, formato `assunto.fato` (`login.ok`, `login.failed`, `user.roles_changed`…). VARCHAR + enum PHP, não ENUM MySQL |
| `user_id` | BIGINT UNSIGNED NULL | **Sobre quem** é o fato. Nulo quando não há conta — login com e-mail inexistente |
| `actor_id` | BIGINT UNSIGNED NULL | **Quem causou** o fato. Difere de `user_id` quando um admin suspende outra pessoa. Nulo em ação de sistema ou de anônimo |
| `identifier` | VARCHAR(255) NULL | O que foi digitado no login que falhou. Único jeito de detectar tentativa contra conta inexistente — ver a ressalva de LGPD abaixo |
| `ip_address` | VARCHAR(45) NULL | IPv6 cabe em 45 |
| `user_agent` | VARCHAR(1023) NULL | Mesma dimensão da `audits` |
| `context` | JSON NULL | Detalhe específico do evento: de/para do status, papéis somados e removidos, habilidade negada |
| `created_at` / `updated_at` | TIMESTAMP | Convenção da pasta 04. **`created_at` é a hora do fato** — não há `occurred_at` separado, que seria uma segunda coluna dizendo o mesmo |

Índices por `(user_id, created_at)`, `(event, created_at)` e
`(ip_address, created_at)` — o terceiro existe para a pergunta "quantas
falhas vieram deste IP na última hora", que é a de detecção de força
bruta.

FKs com `ON DELETE RESTRICT`, como manda a pasta 04: **a trilha segura a
conta**. Apagar um usuário e perder o registro de que ele existiu é
justamente o que uma trilha de segurança não pode permitir. Conta se
encerra com `disabled` (pasta 18 §3), não com `DELETE`.

#### Um desvio da §3, deliberado: registrar não pode negar serviço

A §3 diz que a auditoria roda na mesma transação da mutação e que
mutação sem trilha não é commitada. **Para `security_events` isso não
vale**, e a exceção é consciente: se a gravação da trilha falhasse dentro
do login, uma tabela cheia ou um índice corrompido **trancaria todo mundo
para fora do ERP**. O mecanismo de vigilância derrubaria a operação que
ele existe para proteger — o mesmo formato da armadilha
[P-16](../23-Deploy/04-atualizar-producao.md#7--p-16--a-extensão-psr-quebrava-todo-o-log-da-aplicação),
em que o log quebrava a aplicação.

Então: a gravação é **síncrona** (não vai para fila — evento perdido em
fila que falha é evento perdido em silêncio), mas envolvida em
`try/catch`. Se falhar, o erro vai para `Log::critical()` e a requisição
segue. Isso só é aceitável porque **o log da produção passou a funcionar
em 2026-07-24**; antes disso o `catch` seria um buraco sem fundo.

A diferença em relação à `audits` é de consequência, não de rigor: perder
o diff de uma mutação é perder informação; perder o login é parar a
empresa.

#### Os fatos registrados hoje

| Evento | Sensível? | Quando |
|---|---|---|
| `login.ok` | não | Sessão **efetivamente** aberta. Com 2FA, é depois do segundo fator — acertar a senha e abandonar o desafio não gera este evento, e `last_login_at` também não se mexe |
| `login.failed` | sim | Senha errada, e-mail inexistente, ou **segundo fator errado** (`context.etapa = dois_fatores`, que é o caso mais grave: alguém já tem a senha certa) |
| `logout` | não | — |
| `password.changed` / `password.reset` | sim | Troca autenticada e redefinição por e-mail, respectivamente |
| `permission.denied` | sim | 403 de Policy ou de middleware de permissão |
| `user.invited` / `user.status_changed` / `user.roles_changed` | sim | Ciclo de vida da conta (pasta 18 §3) |
| `two_factor.enabled` | sim | Na **confirmação** do segredo, não na geração — abrir a tela e desistir não muda proteção nenhuma |
| `two_factor.disabled` | sim | Segundo fator desligado |
| `two_factor.recovery_regenerated` | sim | Os oito códigos renovados de uma vez |
| `two_factor.recovery_used` | sim | Entrada por código de recuperação — o sinal de "perdi o celular", e também o caminho de quem tem a lista. `context.restantes` conta quantos sobraram |
| `two_factor.device_remembered` | sim | Um dispositivo passou a dispensar o desafio por 30 dias ([ADR-0021](../27-ADR/ADR-0021-2fa-totp.md)) |

Um TOTP válido **não** gera evento próprio: é o ruído de fundo de um
sistema saudável, mesmo motivo pelo qual `login.ok` não é sensível. O que
interessa é a exceção — o código de recuperação e o dispositivo confiado.

**O que nunca entra no `context`:** códigos de recuperação, o segredo
TOTP, o token do dispositivo lembrado (nem o hash dele). A trilha é lida
por quem tem `audit.view`; guardar ali qualquer uma dessas coisas seria
transformar o registro da fechadura na cópia da chave. O
`two_factor.device_remembered` grava só o `device_id` público, que liga a
entrada da trilha à linha de `two_factor_remembered_devices` sem ajudar a
forjar o cookie.

#### Ressalva de LGPD sobre `identifier`

A coluna guarda o e-mail digitado numa tentativa que falhou — dado
pessoal de alguém que **pode nem ser usuário do sistema** (erro de
digitação de terceiro, ataque com lista de e-mails). É a tensão entre
minimização de dados (pasta 25) e a capacidade de investigar acesso
indevido, e resolvemos a favor de investigar: sem o identificador, uma
sequência de falhas vira ruído sem nome.

Só é gravado em `login.failed`; a rotina de retenção da §3 (2 anos)
alcança essa coluna como qualquer outra.

## 3. Propriedades

- **Imutável**: nenhuma rota/serviço edita ou apaga `audits`/`security_events`; expurgo só por rotina de retenção (pasta 04: 2 anos online, arquivamento depois).
- **Atribuível**: toda entrada tem autor (usuário nominal — por isso contas compartilhadas são proibidas, pasta 18) ou `system`/nome do job.
- **Consultável**: tela "Trilha de auditoria" (permissão `audit.view`, só admin): filtro por entidade, autor, período, tipo; linha do tempo por registro ("ver histórico" em toda ficha).
- **Íntegra**: auditoria roda na mesma transação da mutação — mutação sem trilha não é commitada.

### 3.1 Dois desvios do padrão do pacote (2026-07-23)

| Desvio | Motivo |
|---|---|
| `audits.old_values`/`new_values` são **JSON**, não TEXT | O stub usa TEXT, que trunca em 64 KB. Um pedido com muitos itens ou uma ficha técnica grande cabe nesse limite, e a trilha registraria uma versão mutilada do que aconteceu. Auditoria truncada é auditoria perdida |
| `audit.console` é **`true`** por padrão, não `false` | §2 exige autor em toda mutação — nominal ou `system`. Uma correção feita por `artisan tinker` em produção é exatamente o tipo de mudança que precisa de rastro, e a que mais facilmente ficaria sem. Exceção legítima: o ETL da migração ([pasta 17](../17-Migracao/README.md)) roda com `AUDIT_CONSOLE=false`, porque auditar centenas de milhares de linhas de carga inicial infla a tabela sem informar nada |

Campos sensíveis já estão fora do diff via `$auditExclude` no model
(`password`, `remember_token`, `two_factor_secret`,
`two_factor_recovery_codes`) — e há teste verificando isso, porque um
`audits` com hash de senha transforma a trilha, que muita gente lê com
`audit.view`, em superfície de ataque.

## 4. Dependências

Pasta 18/19 (autor nominal + permissão), pasta 04 (retenção/volume), pasta 25 (LGPD: trilha minimiza dados pessoais — guarda IDs e diffs, não cópias integrais de documentos).

## 5. Boas práticas

- Diffs legíveis na UI (de → para, com formatação pt-BR), não JSON cru.
- Campos sensíveis (senha, tokens) **excluídos** do diff por configuração.
- Relatório mensal de ações sensíveis para o dono (rotina de compliance leve).

## 6. Riscos

| Risco | Mitigação |
|---|---|
| Volume da tabela `audits` crescer rápido | retenção/arquivamento (pasta 04); índices por entidade+data; sem auditoria de tabelas de log |
| Falsa sensação de segurança (auditar ≠ impedir) | auditoria complementa permissões/alçadas, nunca as substitui |

## 7. Evoluções futuras

- Exportação assinada (hash encadeado) se surgir exigência de não-repúdio forte · alertas sobre padrões anômalos (muitos ajustes do mesmo autor) — fase 7.
