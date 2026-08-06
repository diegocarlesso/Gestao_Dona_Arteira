# E-mail transacional

> **Status:** Ativa · **Direções:** ERP→Externo · **Criticidade:** Média · **Última atualização:** 2026-08-06
> **Regras:** BR-310 · **Fase:** Gate 02 · Segue o framework da [pasta 15](README.md).

## 1. Objetivo de negócio

Avisar o cliente nos dois marcos do pedido que hoje têm dono no ERP: confirmação e envio. Sem isso, o cliente só sabe do próprio pedido ligando ou pelo WhatsApp — o e-mail fecha o loop sem depender de alguém da operação lembrar de avisar. Se o envio parar por um dia, ninguém deixa de ser atendido: é aviso, não é o pedido em si (BR-705, falha de integração nunca bloqueia a operação local).

## 2. Contrato

- **Protocolo:** SMTP, via `Illuminate\Notifications` (canal `mail`) — não é uma API HTTP externa própria, então não há `Client.php` nesta integração (diferente da anatomia padrão da pasta 15 §3): quem fala com o servidor é o transporte de e-mail do Laravel.
- **Credenciais:** `MAIL_HOST`/`MAIL_USERNAME`/`MAIL_PASSWORD` no `.env` (nunca no repositório, regra 6 do CLAUDE.md). SMTP da Hostinger em produção (`docs/00-Visao-Geral/05-modelo-de-custos.md` #4); `MAIL_MAILER=log` em desenvolvimento — o e-mail vai para o log, não para uma caixa real.
- **Ambientes:** sem sandbox próprio; `MAIL_MAILER=log`/`array` cobre dev e teste.
- **Limites:** o volume da operação (≤ 1.000 pedidos/mês, ADR-0001) fica bem dentro da faixa gratuita do provedor.

## 3. Entidades e direção de sincronização

| Entidade | Direção | Gatilho | Frequência | Conflito: quem vence |
|---|---|---|---|---|
| Confirmação de pedido | ERP → cliente | evento `OrderConfirmed` | por pedido confirmado | não aplicável (saída única) |
| Rastreio de envio | ERP → cliente | evento `OrderShipped` | por pedido expedido | não aplicável |

**Fora do escopo desta entrega** (linha "e-mail" da pasta 15 §4 previa também NF-e): o e-mail de nota fiscal depende de `InvoiceAuthorized`, que só existe quando o Fiscal for implementado (Gate 05). Entra como evolução natural — mesmo mecanismo, evento novo — quando o gate chegar.

## 4. Mecanismo

- **Local do código:** `App\Modules\Integrations\Email\` — mesma anatomia dos outros sistemas da pasta 15 (é a Integrações que ouve os eventos de Vendas, nunca o contrário; ADR-0020, verificado no CI).
  - `Listeners\EnviarEmailPedidoConfirmado` / `EnviarEmailPedidoEnviado`: ouvem `OrderConfirmed`/`OrderShipped`, resolvem o e-mail do cliente e montam os dados primitivos do e-mail (nunca repassam o model `Order`/`Customer` adiante).
  - `Notifications\PedidoConfirmadoNotification` / `PedidoEnviadoNotification`: `Illuminate\Notifications\Notification` com `ShouldQueue`, enviadas por **rota avulsa** (`Notification::route('mail', $email)`) — o cliente do pedido não é uma conta do ERP, não há `Notifiable` para ele.
- **Fila:** `QUEUE_CONNECTION=database` (ADR-0014). O listener é síncrono (leitura rápida, sem I/O externo); só o envio em si — a notificação — vai para a fila, com `$afterCommit()` porque `OrderConfirmed`/`OrderShipped` nascem dentro da transação dos respectivos Services (`ConfirmOrderService`, `FulfillmentService::expedir()`). Sem isso, o worker poderia pegar o job antes do commit e não achar o pedido — o mesmo raciocínio do convite de acesso (`Identity\Notifications\ConviteDeAcesso`).
- **Retry:** o padrão do Laravel para jobs de notificação (3 tentativas, backoff da fila `database`) — não o backoff 1m/5m/30m/2h da pasta 15 §3, que é para integrações com parceiro externo tratável por status HTTP; aqui uma falha de SMTP transitória se resolve com o retry padrão do worker.
- **Idempotência:** não há reenvio automático — cada evento dispara no máximo um e-mail. Reenviar manualmente (se o cliente disser que não recebeu) é reprocesso operacional fora do escopo desta entrega.
- **Ausência de destinatário não é falha:** pedido de balcão sem cliente, ou cliente sem e-mail cadastrado — o listener retorna sem fazer nada (BR-310). Não há mapping nem log de erro para esse caso: é o caminho normal da maioria dos pedidos hoje.

## 5. Observabilidade

- Log padrão do Laravel para falhas de envio (canal `mail` reporta exceção do transporte). Sem painel próprio: o volume e a criticidade (Média) não justificam uma tela dedicada — o painel de Integrações (pasta 15 §5) é para sync que pode divergir dado; e-mail transacional não tem estado para conciliar, só sucesso ou falha pontual do transporte.
- Se o SMTP cair, os jobs se acumulam na fila `database` como qualquer outro job falho — visível pelas ferramentas padrão do Laravel (`failed_jobs`), sem necessidade de mecanismo próprio.

## 6. Modos de falha e degradação

| Falha | Comportamento do ERP | Ação do operador (runbook) |
|---|---|---|
| SMTP fora do ar | Job de notificação falha e vai para `failed_jobs` após as tentativas padrão; pedido continua confirmado/expedido normalmente (BR-705) | Corrigir credencial/serviço e rodar `php artisan queue:retry` nos jobs falhos |
| Cliente sem e-mail cadastrado | Nenhum e-mail é enviado, silenciosamente (comportamento esperado, BR-310) | Nenhuma — cadastrar e-mail no cliente é decisão da operação, não um erro do sistema |
| `MAIL_MAILER=log` esquecido em produção | E-mails "enviados" só aparecem no log, cliente não recebe nada | Checklist de deploy confere `MAIL_MAILER` (pasta 23) |

## 7. Segurança

Sem webhook de entrada (é saída pura) — não há assinatura para verificar. Dado pessoal que trafega: nome e e-mail do cliente, número do pedido, itens e valores — o mínimo para o aviso fazer sentido (LGPD, pasta 25). Nenhum dado de pagamento ou documento (CPF/CNPJ) entra no corpo do e-mail.

## 8. Plano de descontinuação

Desligar é remover os dois `Event::listen(...)` do `IntegrationsServiceProvider` (ou envolvê-los numa flag de config, se um dia for preciso desligar sem deploy) — não há fila própria para drenar nem estado externo para desfazer.

## 9. Evoluções futuras

- E-mail de nota fiscal (XML/DANFE) quando `InvoiceAuthorized` existir (Gate 05).
- E-mail de cancelamento — hoje fora do escopo (BR-310 cobre só confirmação e envio); avaliar se o cliente precisa saber por e-mail ou se o canal de origem (WhatsApp/telefone) já resolve.
- Feature flag por tipo de e-mail, se a operação quiser desligar um marco sem tocar em código.
