<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneCommandePartenaire extends Model
{
    protected $table = 'lignes_commande_partenaire';

    public $timestamps = false;

    protected $fillable = ['commande_partenaire_id', 'produit_id', 'quantite', 'prix_unitaire', 'prix_cession_unitaire', 'duree_garantie_mois'];

    protected function casts(): array
    {
        return ['quantite' => 'integer', 'prix_unitaire' => 'decimal:2', 'prix_cession_unitaire' => 'decimal:2'];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandePartenaire::class, 'commande_partenaire_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
