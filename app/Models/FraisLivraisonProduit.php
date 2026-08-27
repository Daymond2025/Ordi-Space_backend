<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraisLivraisonProduit extends Model
{
    protected $table = 'frais_livraison_produits';

    protected $fillable = ['produit_id', 'localite_id', 'montant'];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
        ];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function localite(): BelongsTo
    {
        return $this->belongsTo(Localite::class, 'localite_id');
    }
}
