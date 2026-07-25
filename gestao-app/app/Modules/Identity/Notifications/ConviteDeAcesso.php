<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * O e-mail que convida alguém a usar o ERP (pasta 18 §3).
 *
 * **Não carrega senha nenhuma.** O link leva à tela de definição de
 * senha, com um token de uso único do mesmo mecanismo do "esqueci minha
 * senha" — reaproveitado de propósito, em vez de inventar um token de
 * convite paralelo: o fluxo de reset já existe, já é testado, já expira e
 * já é de uso único. Um segundo mecanismo com as mesmas
 * responsabilidades seria uma segunda chance de errar.
 *
 * Enfileirada porque o `UserInvited` nasce dentro do `DB::transaction()`
 * do `InviteUserService`: falar com o SMTP ali dentro seguraria a
 * transação pelo tempo da rede, e um servidor de e-mail fora do ar
 * desfaria a criação do usuário — o convite falharia porque o aviso
 * falhou. Ver `$afterCommit` abaixo.
 */
class ConviteDeAcesso extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Só despacha o job **depois** do commit.
     *
     * Sem isto há uma corrida real: o worker pode pegar o job antes de a
     * transação fechar, consultar o usuário que ainda não existe para
     * outra conexão, e o convite morre num `ModelNotFound` que ninguém vê.
     *
     * Pelo método do trait, não redeclarando `$afterCommit`: a
     * propriedade já existe em `Queueable` sem tipo, e declará-la aqui
     * como `bool` é erro fatal de composição de trait.
     */
    public function __construct()
    {
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
        $token = Password::broker()->createToken($notifiable);

        $url = url(route('password.reset', [
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false));

        return (new MailMessage)
            ->subject('Seu acesso ao ERP da Dona Arteira')
            ->greeting("Olá, {$notifiable->name}!")
            ->line('Foi criada uma conta para você no sistema de gestão da Dona Arteira.')
            ->line('Para começar, defina uma senha sua. Ela precisa ter no mínimo 12 caracteres e é conferida contra vazamentos públicos conhecidos.')
            ->action('Definir minha senha', url($url))
            // O prazo do token do Laravel é curto (60 minutos por padrão),
            // e convite costuma ser lido horas depois. Em vez de esticar o
            // prazo — o que enfraqueceria todo reset de senha do sistema —,
            // o e-mail ensina o caminho de volta, que dá no mesmo lugar.
            ->line('Se o botão acima disser que o link expirou, use "Esqueci minha senha" na tela de entrada com este mesmo e-mail — funciona igual.')
            ->salutation('Até já!');
    }
}
