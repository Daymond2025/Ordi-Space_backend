<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetCodeNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe OrdiSpace')
            ->line("Voici votre code de réinitialisation : {$this->code}")
            ->line('Ce code expire dans '.PASSWORD_RESET_EXPIRATION_MINUTES.' minutes.')
            ->line("Si vous n'êtes pas à l'origine de cette demande, ignorez ce message — votre mot de passe ne changera pas.");
    }
}
