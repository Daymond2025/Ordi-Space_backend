<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prix de revente fixé par le partenaire — jamais inférieur à son prix de cession. */
class OffrePartenaire extends Model
{
    protected $table = 'offres_partenaire';

    protected $fillable = ['partenaire_id', 'produit_id', 'prix_revente', 'actif'];

    protected function casts(): array
    {
        return ['prix_revente' => 'decimal:2', 'actif' => 'boolean'];
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
