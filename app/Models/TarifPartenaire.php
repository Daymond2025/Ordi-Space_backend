<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Exception de prix de cession pour un produit et un partenaire (sinon : remise par défaut). */
class TarifPartenaire extends Model
{
    protected $table = 'tarifs_partenaire';

    protected $fillable = ['partenaire_id', 'produit_id', 'prix_cession'];

    protected function casts(): array
    {
        return ['prix_cession' => 'decimal:2'];
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
