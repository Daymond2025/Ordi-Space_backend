<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique du portefeuille commercial — voir migration
 * create_transactions_portefeuille_commerciaux_table.
 */
class TransactionPortefeuilleCommercial extends Model
{
    protected $table = 'transactions_portefeuille_commerciaux';
    public $timestamps = false;

    protected $fillable = [
        'commercial_id', 'commande_id', 'type', 'montant', 'libelle',
        'client_nom', 'client_localite', 'solde_apres', 'date_transaction',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'solde_apres' => 'decimal:2',
            'date_transaction' => 'datetime',
        ];
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class, 'commercial_id', 'user_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }
}
