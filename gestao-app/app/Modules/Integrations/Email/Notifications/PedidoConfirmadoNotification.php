<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Email\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de confirmação de pedido — docs/15-Integracoes/01-email-transacional.md, BR-310.
 *
 * Enviada por rota avulsa (`Notification::route('mail', $email)`), não a
 * um model `Notifiable`: quem recebe é o e-mail do cliente do pedido, não
 * uma conta com login no ERP. O listener já filtrou "sem cliente" e "sem
 * e-mail" antes de chegar aqui — esta classe só sabe montar a mensagem.
 *
 * Enfileirada e adiada para depois do commit pela mesma razão da
 * `Identity\Notifications\ConviteDeAcesso`: `OrderConfirmed` nasce dentro
 * do `DB::transaction()` do `ConfirmOrderService`, e falar com o SMTP
 * antes do commit arriscaria notificar um pedido que a transação ainda
 * pode desfazer.
 */
class PedidoConfirmadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{nome: string, qtd: string, preco: string}>  $itens
     */
    public function __construct(
        private readonly string $numeroPedido,
        private readonly string $nomeCliente,
        private readonly array $itens,
        private readonly string $total,
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
            ->subject("Pedido #{$this->numeroPedido} confirmado — Dona Arteira")
            ->greeting("Olá, {$this->nomeCliente}!")
            ->line("Recebemos seu pedido #{$this->numeroPedido} e ele já está confirmado.");

        foreach ($this->itens as $item) {
            $mail->line("• {$item['qtd']}x {$item['nome']} — {$item['preco']}");
        }

        return $mail
            ->line("Total do pedido: {$this->total}")
            ->line('Assim que o pedido for enviado, você recebe um novo e-mail com o rastreio.')
            ->salutation('Obrigado por comprar com a gente!');
    }
}
