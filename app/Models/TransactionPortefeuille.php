<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionPortefeuille extends Model
{
    protected $table = 'transactions_portefeuille';
    public $timestamps = false;

    protected $fillable = ['client_id', 'type', 'montant', 'motif', 'commande_id', 'solde_apres', 'date_transaction'];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'solde_apres' => 'decimal:2',
            'date_transaction' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }
}
