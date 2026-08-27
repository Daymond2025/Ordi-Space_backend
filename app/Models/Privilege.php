<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Privilege extends Model
{
    protected $table = 'privileges';

    protected $fillable = [
        'titre', 'sous_titre', 'description', 'type_privilege', 'valeur',
        'code_promo', 'limite_utilisation_par_client', 'date_debut', 'date_fin',
        'actif', 'ordre_affichage', 'couleur_debut', 'couleur_fin',
    ];

    protected function casts(): array
    {
        return [
            'valeur' => 'decimal:2',
            'date_debut' => 'date',
            'date_fin' => 'date',
            'actif' => 'boolean',
        ];
    }

    public function utilisations(): HasMany
    {
        return $this->hasMany(UtilisationPrivilege::class, 'privilege_id');
    }

    public function estValidePourDate(): bool
    {
        $aujourdhui = now()->toDateString();

        return (! $this->date_debut || $this->date_debut->toDateString() <= $aujourdhui)
            && (! $this->date_fin || $this->date_fin->toDateString() >= $aujourdhui);
    }

    /**
     * Calcule le montant de la remise (sur montant_total) pour un montant de
     * commande donné. La livraison gratuite n'agit pas ici : elle neutralise
     * le frais de livraison directement dans CommandeController::calculerFraisLivraison().
     */
    public function calculerRemise(float $montantCommande): float
    {
        return match ($this->type_privilege) {
            TYPE_PRIVILEGE_REMISE_POURCENTAGE => round($montantCommande * ((float) $this->valeur / 100), 2),
            TYPE_PRIVILEGE_REMISE_MONTANT => min((float) $this->valeur, $montantCommande),
            default => 0.0,
        };
    }
}
