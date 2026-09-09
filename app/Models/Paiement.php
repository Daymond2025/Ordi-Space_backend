<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Paiement extends Model
{
    protected $table = 'paiements';

    protected $fillable = [
        'commande_id', 'livreur_id', 'montant', 'mode_paiement',
        'statut_paiement', 'reference_transaction', 'date_paiement',
        'date_limite_depot', 'date_depot',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_paiement' => 'datetime',
            'date_limite_depot' => 'datetime',
            'date_depot' => 'datetime',
            // Donnée sensible (n° Mobile Money / n° reçu) chiffrée au repos.
            'reference_transaction' => 'encrypted',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class, 'livreur_id', 'user_id');
    }
}
