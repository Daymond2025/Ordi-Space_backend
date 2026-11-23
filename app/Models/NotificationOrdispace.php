<?php

namespace App\Models;

use App\Services\PushNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

class NotificationOrdispace extends Model
{
    protected $table = 'notifications_ordispace';

    public $timestamps = false;

    protected $fillable = ['user_id', 'type_notification', 'titre', 'contenu', 'lien', 'urgente', 'lu', 'date_envoi'];

    protected function casts(): array
    {
        return ['lu' => 'boolean', 'urgente' => 'boolean', 'date_envoi' => 'datetime'];
    }

    /**
     * Toute notification enregistrée part aussi sur le téléphone (Web Push),
     * pour l'utilisateur qui n'a pas l'app ouverte. Envoi après la réponse
     * HTTP (defer) : l'action métier n'attend jamais le service push, et un
     * échec d'envoi n'a aucun effet sur elle.
     */
    protected static function booted(): void
    {
        static::created(function (NotificationOrdispace $notification) {
            defer(function () use ($notification) {
                try {
                    $user = User::find($notification->user_id);
                    if ($user) {
                        app(PushNotificationService::class)->envoyer(
                            $user,
                            $notification->titre ?? "Ordi'Space",
                            $notification->contenu,
                            ['id' => $notification->id, 'lien' => $notification->lien, 'urgente' => $notification->urgente],
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('Push non envoyé', ['notification_id' => $notification->id, 'erreur' => $e->getMessage()]);
                }
            });
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
