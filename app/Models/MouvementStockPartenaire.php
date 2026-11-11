<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Journal append-only du stock partenaire (quantité signée, stock après mouvement). */
class MouvementStockPartenaire extends Model
{
    protected $table = 'mouvements_stock_partenaire';

    public const UPDATED_AT = null;

    protected $fillable = [
        'partenaire_id', 'produit_id', 'type', 'quantite', 'stock_apres',
        'approvisionnement_id', 'commande_partenaire_id', 'motif', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['quantite' => 'integer', 'stock_apres' => 'integer'];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
