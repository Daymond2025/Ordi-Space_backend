<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Partenaire Ordi'Space : représentant d'Ordi'Space dans une ou plusieurs
 * villes. Il achète les produits à son prix de cession (payé d'avance, voir
 * ApprovisionnementPartenaire), les revend au prix qu'il fixe
 * (OffrePartenaire) et garde la différence. Les clients de ses villes le
 * choisissent sur la fiche produit (plusieurs partenaires par ville).
 */
class Partenaire extends Model
{
    protected $table = 'partenaires';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'nom_boutique', 'adresse_boutique', 'remise_defaut',
        'contact_pro', 'horaires_ouverture', 'lien_maps', 'nom_gerant', 'telephone_gerant',
    ];

    protected function casts(): array
    {
        return ['remise_defaut' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function localites(): BelongsToMany
    {
        return $this->belongsToMany(Localite::class, 'partenaire_localite', 'partenaire_id', 'localite_id');
    }

    public function tarifs(): HasMany
    {
        return $this->hasMany(TarifPartenaire::class, 'partenaire_id');
    }

    public function offres(): HasMany
    {
        return $this->hasMany(OffrePartenaire::class, 'partenaire_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(StockPartenaire::class, 'partenaire_id');
    }

    public function approvisionnements(): HasMany
    {
        return $this->hasMany(ApprovisionnementPartenaire::class, 'partenaire_id');
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(CommandePartenaire::class, 'partenaire_id');
    }

    /** Compte actif (une suspension du compte retire aussi la boutique des clients). */
    public function scopeActifs(Builder $query): void
    {
        $query->whereHas('user', fn ($q) => $q->where('statut_compte', STATUT_COMPTE_ACTIF));
    }

    public function scopeCouvrant(Builder $query, int $localiteId): void
    {
        $query->whereHas('localites', fn ($q) => $q->whereKey($localiteId));
    }

    public function estActif(): bool
    {
        return $this->user?->statut_compte === STATUT_COMPTE_ACTIF;
    }

    public function quantiteEnStock(int $produitId): int
    {
        return (int) $this->stocks()->where('produit_id', $produitId)->value('quantite');
    }
}
