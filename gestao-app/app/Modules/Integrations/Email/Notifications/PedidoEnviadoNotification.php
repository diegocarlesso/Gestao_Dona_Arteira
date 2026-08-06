<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Email\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de rastreio — docs/15-Integracoes/01-email-transacional.md, BR-310.
 *
 * Mesmo raciocínio da `PedidoConfirmadoNotification`: rota avulsa (o
 * cliente não é `Notifiable`), enfileirada e adiada para depois do commit
 * porque `OrderShipped` nasce dentro do `DB::transaction()` do
 * `FulfillmentService::expedir()`.
 *
 * `$codigoRastreio` e `$transportadora` são nuláveis porque a expedição
 * aceita expedir sem os dois preenchidos (docs/10-Vendas §2.1 — corte 3
 * expede sem o gate fiscal e sem Melhor Envio por ora); o e-mail avisa do
 * envio mesmo sem rastreio e não promete um código que não existe.
 */
class PedidoEnviadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $numeroPedido,
        private readonly string $nomeCliente,
        private readonly ?string $codigoRastreio,
        private readonly ?string $transportadora,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Pedido #{$this->numeroPedido} enviado — Dona Arteira")
            ->greeting("Olá, {$this->nomeCliente}!")
            ->line("Seu pedido #{$this->numeroPedido} acabou de ser enviado.");

        if ($this->codigoRastreio !== null) {
            $transportadora = $this->transportadora ?? 'transportadora';
            $mail->line("Código de rastreio ({$transportadora}): {$this->codigoRastreio}");
        } else {
            $mail->line('O código de rastreio será informado assim que disponível.');
        }

        return $mail->salutation('Obrigado por comprar com a gente!');
    }
}
