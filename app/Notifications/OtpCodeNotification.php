<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpCodeNotification extends Notification
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
            ->subject('Votre code de connexion OrdiSpace')
            ->line("Votre code de vérification est : {$this->code}")
            ->line('Ce code expire dans 5 minutes.')
            ->line("Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.");
    }
}
