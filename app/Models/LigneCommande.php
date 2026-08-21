<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LigneCommande extends Model
{
    protected $table = 'lignes_commande';

    protected $fillable = ['commande_id', 'produit_id', 'quantite', 'prix_unitaire'];

    protected function casts(): array
    {
        return ['prix_unitaire' => 'decimal:2'];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function garantie(): HasOne
    {
        return $this->hasOne(Garantie::class, 'ligne_commande_id');
    }

    public function retour(): HasOne
    {
        return $this->hasOne(Retour::class, 'ligne_commande_id');
    }

    /**
     * Historique des abonnements GarantiX souscrits sur cet ordinateur
     * (renouvelés chaque année) — le plus récent en premier.
     */
    public function abonnementsGarantix(): HasMany
    {
        return $this->hasMany(AbonnementGarantix::class, 'ligne_commande_id')->latest('date_debut');
    }
}
