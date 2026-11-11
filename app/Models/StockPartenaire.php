<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quantité en stock chez le partenaire — modifiée uniquement par StockPartenaireService. */
class StockPartenaire extends Model
{
    protected $table = 'stocks_partenaire';

    protected $fillable = ['partenaire_id', 'produit_id', 'quantite'];

    protected function casts(): array
    {
        return ['quantite' => 'integer'];
    }

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'partenaire_id', 'user_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
