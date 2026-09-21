<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paiement de confirmation d'une commande de la page acheteur — voir la migration acomptes_confirmation. */
class AcompteConfirmation extends Model
{
    protected $table = 'acomptes_confirmation';

    protected $fillable = [
        'token', 'commande_id', 'montant', 'statut', 'wave_checkout_session_id', 'wave_launch_url',
        'date_paiement', 'donnees', 'erreur',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'date_paiement' => 'datetime',
            'donnees' => 'array',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function estPaye(): bool
    {
        return $this->statut === STATUT_PAIEMENT_CONFIRME;
    }

    /** Payé, mais la commande n'a pas pu être créée (stock épuisé entre-temps, produit retiré…) : à traiter par l'Admin. */
    public function estAnomalie(): bool
    {
        return $this->estPaye() && $this->commande_id === null;
    }
}
