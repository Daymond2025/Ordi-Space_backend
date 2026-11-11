<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneApprovisionnementPartenaire extends Model
{
    protected $table = 'lignes_approvisionnement_partenaire';

    public $timestamps = false;

    protected $fillable = ['approvisionnement_id', 'produit_id', 'quantite', 'prix_cession_unitaire'];

    protected function casts(): array
    {
        return ['quantite' => 'integer', 'prix_cession_unitaire' => 'decimal:2'];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
