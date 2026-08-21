<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalAudit extends Model
{
    protected $table = 'journal_audit';
    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'entite_concernee', 'details', 'adresse_ip', 'date_heure'];

    protected function casts(): array
    {
        return ['date_heure' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Point d'entrée unique pour journaliser une action métier — alimente
     * le fil "Activités" de la fiche client admin. Table jusqu'ici définie
     * mais jamais écrite ; voir les points d'appel dans les contrôleurs
     * (commande créée, abonnement GarantiX, panne déclarée, panier, etc.).
     */
    public static function enregistrer(int $userId, string $action, ?string $entiteConcernee = null, ?string $details = null): self
    {
        return static::create([
            'user_id' => $userId,
            'action' => $action,
            'entite_concernee' => $entiteConcernee,
            'details' => $details,
            'adresse_ip' => request()->ip(),
            'date_heure' => now(),
        ]);
    }
}
