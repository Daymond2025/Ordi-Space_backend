<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Garantie extends Model
{
    protected $table = 'garanties';

    protected $fillable = ['ligne_commande_id', 'date_debut', 'date_fin', 'type_garantie', 'conditions'];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date'];
    }

    /**
     * Génère la garantie de base (gratuite, incluse à l'achat) pour chaque
     * ligne d'une commande livrée, si le produit porte une durée de garantie
     * et qu'aucune garantie n'existe déjà pour cette ligne. Distinct de
     * GarantiX (abonnement payant optionnel, souscrit à part par le client).
     */
    public static function genererPourCommande(Commande $commande): void
    {
        $commande->loadMissing('lignes.produit', 'lignes.garantie');

        foreach ($commande->lignes as $ligne) {
            if (! $ligne->produit || ! $ligne->produit->duree_garantie_mois || $ligne->garantie) {
                continue;
            }

            static::create([
                'ligne_commande_id' => $ligne->id,
                'date_debut' => now(),
                'date_fin' => now()->addMonths($ligne->produit->duree_garantie_mois),
                'type_garantie' => TYPE_GARANTIE_ORDISPACE,
                'conditions' => 'Garantie standard incluse à l\'achat.',
            ]);
        }
    }

    public function ligneCommande(): BelongsTo
    {
        return $this->belongsTo(LigneCommande::class, 'ligne_commande_id');
    }

    public function demandesSav(): HasMany
    {
        return $this->hasMany(DemandeSav::class, 'garantie_id');
    }

    public function estValide(): bool
    {
        return now()->lte($this->date_fin);
    }
}
