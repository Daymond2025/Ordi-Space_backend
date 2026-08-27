<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionPortefeuilleFournisseur extends Model
{
    protected $table = 'transactions_portefeuille_fournisseurs';
    public $timestamps = false;

    protected $fillable = [
        'fournisseur_id', 'type', 'montant', 'motif', 'commande_id', 'acteur_id', 'solde_apres', 'date_transaction',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'solde_apres' => 'decimal:2',
            'date_transaction' => 'datetime',
        ];
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id', 'user_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }
}
