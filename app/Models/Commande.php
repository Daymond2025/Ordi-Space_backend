<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Commande extends Model
{
    protected $table = 'commandes';

    protected $fillable = [
        'client_id', 'commercial_id', 'coordinateur_id', 'canal_vente_id',
        'statut_commande', 'montant_total', 'montant_remise', 'privilege_id', 'date_commande',
        'date_validation', 'parrain_id', 'parrainage_recompense_versee', 'livraison_gratuite_appliquee',
    ];

    protected function casts(): array
    {
        return [
            'montant_total' => 'decimal:2',
            'montant_remise' => 'decimal:2',
            'date_commande' => 'datetime',
            'date_validation' => 'datetime',
        ];
    }

    public function privilege(): BelongsTo
    {
        return $this->belongsTo(Privilege::class, 'privilege_id');
    }

    public function parrain(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'parrain_id', 'user_id');
    }

    public function montantNet(): float
    {
        return (float) $this->montant_total - (float) $this->montant_remise;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class, 'commercial_id', 'user_id');
    }

    public function coordinateur(): BelongsTo
    {
        return $this->belongsTo(Coordinateur::class, 'coordinateur_id', 'user_id');
    }

    public function canalVente(): BelongsTo
    {
        return $this->belongsTo(CanalVente::class, 'canal_vente_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommande::class, 'commande_id');
    }

    public function livraison(): HasOne
    {
        return $this->hasOne(Livraison::class, 'commande_id');
    }

    public function paiement(): HasOne
    {
        return $this->hasOne(Paiement::class, 'commande_id');
    }

    public function estValidee(): bool
    {
        return $this->coordinateur_id !== null;
    }

    /**
     * "Carte invitation" : le parrain est crédité sur son portefeuille dès
     * que la commande de son filleul — portant sur un véritable ordinateur
     * (fournisseur → coordinateur, pas un accessoire/logiciel publié par
     * l'Admin) — est livrée. Une seule fois par commande. Partagé entre le
     * flux normal (LivraisonController::livrer) et l'override admin.
     */
    public function crediterParrainageSiEligible(): void
    {
        if (! $this->parrain_id || $this->parrainage_recompense_versee) {
            return;
        }

        $concerneUnOrdinateur = $this->lignes()
            ->whereHas('produit', fn ($q) => $q->whereNotNull('fournisseur_id'))
            ->exists();

        $privilegeParrainage = Privilege::where('type_privilege', TYPE_PRIVILEGE_PARRAINAGE)
            ->where('actif', true)
            ->first();

        if (! $concerneUnOrdinateur || ! $privilegeParrainage || ! $privilegeParrainage->valeur) {
            return;
        }

        $parrain = Client::find($this->parrain_id);
        $parrain->crediterPortefeuille(
            (float) $privilegeParrainage->valeur,
            "Parrainage — commande #{$this->id}",
            $this->id
        );

        $this->update(['parrainage_recompense_versee' => true]);
    }
}
