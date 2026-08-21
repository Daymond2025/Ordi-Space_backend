<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Produit extends Model
{
    protected $table = 'produits';

    protected $fillable = [
        'fournisseur_id', 'categorie_id', 'nom_produit', 'description',
        'prix', 'quantite_stock', 'statut_produit', 'date_ajout',
        'type_livraison', 'duree_garantie_mois',
    ];

    protected function casts(): array
    {
        return [
            'prix' => 'decimal:2',
            'date_ajout' => 'datetime',
        ];
    }

    public function estNumerique(): bool
    {
        return $this->type_livraison === TYPE_LIVRAISON_NUMERIQUE;
    }

    /**
     * true pour les accessoires/logiciels publiés directement par
     * l'Administrateur, sans fournisseur ni cycle de validation coordinateur.
     */
    public function estPublieParAdmin(): bool
    {
        return $this->fournisseur_id === null;
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id', 'user_id');
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class, 'categorie_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ImageProduit::class, 'produit_id')->orderBy('ordre_affichage');
    }

    public function validations(): HasMany
    {
        return $this->hasMany(ValidationProduit::class, 'produit_id');
    }

    public function lignesCommande(): HasMany
    {
        return $this->hasMany(LigneCommande::class, 'produit_id');
    }

    public function estVisibleALaVente(): bool
    {
        return $this->statut_produit === STATUT_PRODUIT_VALIDE;
    }
}
