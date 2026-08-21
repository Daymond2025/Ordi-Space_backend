<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LignePanier extends Model
{
    protected $table = 'lignes_panier';

    protected $fillable = ['panier_id', 'produit_id', 'quantite'];

    public function panier(): BelongsTo
    {
        return $this->belongsTo(Panier::class, 'panier_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
