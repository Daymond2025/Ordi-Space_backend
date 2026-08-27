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
        'statut_commande', 'montant_total', 'montant_remise', 'frais_livraison', 'privilege_id', 'date_commande',
        'date_validation', 'parrain_id', 'parrainage_recompense_versee', 'livraison_gratuite_appliquee',
        'commissions_fournisseurs_versees',
    ];

    protected function casts(): array
    {
        return [
            'montant_total' => 'decimal:2',
            'montant_remise' => 'decimal:2',
            'frais_livraison' => 'decimal:2',
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
        return (float) $this->montant_total - (float) $this->montant_remise + (float) $this->frais_livraison;
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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'commande_id');
    }

    public function estValidee(): bool
    {
        return $this->coordinateur_id !== null;
    }

    /**
     * Visibilité générale de la commande (détail, suivi) — extrait de
     * l'ancien CommandeController::autoriserAcces(), comportement identique.
     */
    public function estAccessiblePar(User $user): bool
    {
        return match ($user->type_utilisateur) {
            ROLE_CLIENT => $this->client_id === $user->id,
            ROLE_COMMERCIAL => $this->commercial_id === $user->id,
            ROLE_LIVREUR => $this->livraison?->livreur_id === $user->id,
            ROLE_COORDINATEUR, ROLE_ADMINISTRATEUR => true,
            default => false,
        };
    }

    /**
     * Discussion commande (Espace Coordinateur) — périmètre plus étroit que
     * estAccessiblePar() : seuls fournisseur/commercial/coordinateur y
     * participent d'après le cahier des charges (ni client, ni livreur).
     */
    public function estAccessibleConversationPar(User $user): bool
    {
        return match ($user->type_utilisateur) {
            ROLE_ADMINISTRATEUR, ROLE_COORDINATEUR => true,
            ROLE_COMMERCIAL => $this->commercial_id === $user->id,
            ROLE_FOURNISSEUR => $this->lignes()->whereHas('produit', fn ($q) => $q->where('fournisseur_id', $user->id))->exists(),
            default => false,
        };
    }

    /**
     * "Carte invitation" : le parrain est crédité sur son portefeuille dès
     * que la commande de son filleul — portant sur un produit à livraison
     * physique (un ordinateur, publié par un fournisseur ou directement par
     * l'Admin), jamais une licence numérique — est livrée. Une seule fois
     * par commande. Partagé entre le flux normal (LivraisonController::livrer)
     * et l'override admin.
     */
    public function crediterParrainageSiEligible(): void
    {
        if (! $this->parrain_id || $this->parrainage_recompense_versee) {
            return;
        }

        $concerneUnOrdinateur = $this->lignes()
            ->whereHas('produit', fn ($q) => $q->where('type_livraison', TYPE_LIVRAISON_PHYSIQUE))
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

    /**
     * Crédite chaque fournisseur concerné par cette commande (montant net de
     * sa commission, propre à chaque fournisseur) une seule fois — même
     * garde-fou d'idempotence que crediterParrainageSiEligible(). Les
     * produits publiés directement par l'Admin (sans fournisseur) n'ont pas
     * de commission à verser.
     */
    public function crediterFournisseursSiEligible(): void
    {
        if ($this->commissions_fournisseurs_versees) {
            return;
        }

        $lignesParFournisseur = $this->lignes()->with('produit.fournisseur')->get()
            ->filter(fn (LigneCommande $ligne) => $ligne->produit && ! $ligne->produit->estPublieParAdmin())
            ->groupBy(fn (LigneCommande $ligne) => $ligne->produit->fournisseur_id);

        foreach ($lignesParFournisseur as $fournisseurId => $lignes) {
            $fournisseur = Fournisseur::find($fournisseurId);

            if (! $fournisseur) {
                continue;
            }

            $montantBrut = $lignes->sum(fn (LigneCommande $l) => (float) $l->prix_unitaire * $l->quantite);
            $montantNet = round($montantBrut * (1 - (float) $fournisseur->taux_commission / 100), 2);

            $fournisseur->crediterPortefeuille($montantNet, "Vente commande #{$this->id}", $this->id);
        }

        $this->update(['commissions_fournisseurs_versees' => true]);
    }
}
