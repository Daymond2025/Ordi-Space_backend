<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Commande extends Model
{
    protected $table = 'commandes';

    protected $fillable = [
        'client_id', 'commercial_id', 'coordinateur_id', 'canal_vente_id',
        'statut_commande', 'montant_total', 'montant_remise', 'frais_livraison', 'bonus_offerts', 'notes', 'privilege_id', 'date_commande',
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

    /** Vente "Boutique" d'un livreur, si cette commande en est une (voir VenteBoutique). */
    public function venteBoutique(): HasOne
    {
        return $this->hasOne(VenteBoutique::class, 'commande_id');
    }

    public function paiement(): HasOne
    {
        return $this->hasOne(Paiement::class, 'commande_id');
    }

    /** Paiement de confirmation (page acheteur) qui a donné naissance à cette commande, s'il y en a un. */
    public function acompte(): HasOne
    {
        return $this->hasOne(AcompteConfirmation::class, 'commande_id');
    }

    /** Montant de la confirmation effectivement payée (jamais remboursable), 0 si aucune. */
    public function acomptePaye(): float
    {
        $acompte = $this->acompte;

        return $acompte && $acompte->statut === STATUT_PAIEMENT_CONFIRME ? (float) $acompte->montant : 0.0;
    }

    /**
     * Reliquat : ce que le client doit encore, à la livraison — le total moins la confirmation qu'il
     * a déjà payée en ligne. Le livreur l'encaisse pour le compte d'Ordi'Space.
     */
    public function reliquat(): float
    {
        return max(0.0, $this->montantNet() - $this->acomptePaye());
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
            // Écran détail/suivi commande (app Fournisseur) — même périmètre
            // qu'estAccessibleConversationPar() (au moins une ligne sur un de
            // ses produits), utilisé jusqu'ici uniquement pour la discussion.
            ROLE_FOURNISSEUR => $this->lignes()->whereHas('produit', fn ($q) => $q->where('fournisseur_id', $user->id))->exists(),
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

            // Nouveau flux (prix_partenaire_unitaire renseigné, produit publié
            // via l'écran de publication) : le fournisseur touche l'intégralité
            // de son prix partenaire, taux_commission ne s'applique plus — la
            // marge Ordi'Space vient de l'écart prix de vente/prix partenaire,
            // pas d'une ponction sur le fournisseur. Lignes historiques
            // (prix_partenaire_unitaire nul, produits publiés avant cette
            // fonctionnalité) : ancien calcul taux_commission inchangé.
            $lignesNouveauFlux = $lignes->filter(fn (LigneCommande $l) => $l->prix_partenaire_unitaire !== null);
            $lignesHistoriques = $lignes->filter(fn (LigneCommande $l) => $l->prix_partenaire_unitaire === null);

            $montantNouveauFlux = $lignesNouveauFlux->sum(fn (LigneCommande $l) => (float) $l->prix_partenaire_unitaire * $l->quantite);

            $montantBrutHistorique = $lignesHistoriques->sum(fn (LigneCommande $l) => (float) $l->prix_unitaire * $l->quantite);
            $montantNetHistorique = round($montantBrutHistorique * (1 - (float) $fournisseur->taux_commission / 100), 2);
            $commissionPrelevee = round($montantBrutHistorique - $montantNetHistorique, 2);

            $montantNet = round($montantNouveauFlux + $montantNetHistorique, 2);

            $fournisseur->crediterPortefeuille($montantNet, "Vente commande #{$this->id}", $this->id, $commissionPrelevee);
        }

        $this->update(['commissions_fournisseurs_versees' => true]);
    }

    /**
     * "Commande validé" ("Mes paiements", app Commercial) — crédite un
     * montant FIXE (COMMISSION_COMMERCIAL_PAR_VENTE) par commande validée,
     * quel que soit le produit vendu (confirmé PDG — jamais
     * Produit::commission_agent, un champ du formulaire Coordinateur sans
     * lien avec ce montant). Idempotent (une seule transaction "credit" par
     * commande) pour rester correct même si la commande repasse par
     * "validée" plusieurs fois (ex. après "reportée"). Le verrou sur le
     * commercial + la contrainte unique (commande_id, type) (migration
     * ..._add_unique_commande_type_to_transactions_portefeuille_commerciaux)
     * empêchent un double crédit si deux requêtes concurrentes (ex.
     * "valider" rejouée deux fois) passent toutes les deux le premier
     * exists() avant qu'aucune n'ait commité son insert (audit sécurité).
     */
    public function crediterCommissionCommercialSiEligible(): void
    {
        if (! $this->commercial_id) {
            return;
        }

        if (TransactionPortefeuilleCommercial::where('commande_id', $this->id)->where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)->exists()) {
            return;
        }

        // Autonome (ne suppose jamais que l'appelant a déjà chargé ces
        // relations) : appelé aussi bien depuis appliquerChangementStatut()
        // que directement depuis CommandeController::valider() (chemin
        // principal de validation, qui ne passe pas par cette méthode-là).
        $this->loadMissing('client.user', 'livraison.adresse.localite');

        try {
            DB::transaction(function () {
                $commercial = Commercial::where('user_id', $this->commercial_id)->lockForUpdate()->first();
                if (! $commercial) {
                    return;
                }

                // Revérifié sous verrou : la lecture précédente (ligne ~255)
                // n'était pas protégée contre une requête concurrente.
                if (TransactionPortefeuilleCommercial::where('commande_id', $this->id)->where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)->exists()) {
                    return;
                }

                $commercial->crediterPortefeuille(
                    COMMISSION_COMMERCIAL_PAR_VENTE, 'Commande validé', $this->id, $this->nomClientPourPortefeuille(), $this->localitePourPortefeuille()
                );
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Dernier filet : contrainte unique (commande_id, type) violée par
            // une requête concurrente qui a gagné la course — déjà crédité.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    /**
     * "Commande annulée" — reprise de la commission déjà créditée (commande
     * validée, puis finalement annulée). Distinct de "Retrait effectué"
     * (Admin\RetraitController::valider()) : ce libellé-là est réservé au
     * vrai retrait Mobile Money demandé par le commercial — jamais utilisé
     * ici, pour ne pas laisser croire à un retrait réel. Pas de
     * double-reprise si déjà fait (même protection verrou + contrainte
     * unique qu'au crédit, voir crediterCommissionCommercialSiEligible()).
     */
    public function reprendreCommissionCommercialSiEligible(): void
    {
        if (! $this->commercial_id) {
            return;
        }

        $credit = TransactionPortefeuilleCommercial::where('commande_id', $this->id)->where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)->first();
        if (! $credit) {
            return;
        }

        if (TransactionPortefeuilleCommercial::where('commande_id', $this->id)->where('type', TYPE_TRANSACTION_PORTEFEUILLE_DEBIT)->exists()) {
            return;
        }

        $this->loadMissing('client.user', 'livraison.adresse.localite');

        try {
            DB::transaction(function () use ($credit) {
                $commercial = Commercial::where('user_id', $this->commercial_id)->lockForUpdate()->first();
                if (! $commercial) {
                    return;
                }

                if (TransactionPortefeuilleCommercial::where('commande_id', $this->id)->where('type', TYPE_TRANSACTION_PORTEFEUILLE_DEBIT)->exists()) {
                    return;
                }

                $commercial->debiterPortefeuille((float) $credit->montant, 'Commande annulée', $this->id, $this->nomClientPourPortefeuille(), $this->localitePourPortefeuille());
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    private function nomClientPourPortefeuille(): ?string
    {
        $nom = trim(($this->client?->user?->prenom ?? '').' '.($this->client?->user?->nom ?? ''));

        return $nom !== '' ? $nom : null;
    }

    private function localitePourPortefeuille(): ?string
    {
        return $this->livraison?->adresse?->localite?->nom;
    }

    /**
     * Effets de bord partagés d'un changement de statut forcé — extrait de
     * l'override Admin (Api\Admin\CommandeController::changerStatut()) pour
     * être réutilisé par le nouveau point d'entrée Coordinateur (liberté
     * totale de statut, voir Api\CommandeController::changerStatut()).
     * `$coordinateurId` n'est attribué que si la cible est "validee" et que
     * l'appelant est réellement le responsable de cette validation — laissé
     * à `null` pour ne rien attribuer (ex: override admin sans lien direct).
     */
    public function appliquerChangementStatut(string $cible, ?int $livreurId = null, ?int $coordinateurId = null): void
    {
        $this->loadMissing('livraison.adresse.localite', 'lignes.produit', 'client.user');

        DB::transaction(function () use ($cible, $livreurId, $coordinateurId) {
            if ($cible === STATUT_COMMANDE_ANNULEE && $this->statut_commande !== STATUT_COMMANDE_ANNULEE) {
                foreach ($this->lignes as $ligne) {
                    $ligne->produit?->increment('quantite_stock', $ligne->quantite);
                }

                // Le livreur a déjà le colis en main (mission acceptée, en
                // route) : la livraison est marquée échouée et signalée pour
                // un retour physique au dépôt — cf. écran "Les Missions".
                if ($this->livraison?->livreur_id && $this->livraison->statut_livraison === STATUT_LIVRAISON_EN_COURS) {
                    $this->livraison->update([
                        'statut_livraison' => STATUT_LIVRAISON_ECHOUEE,
                        'retour_necessaire' => true,
                        'statut_retour' => STATUT_RETOUR_LIVRAISON_EN_COURS,
                    ]);
                }

                // "Commande annulée" ("Mes paiements", app Commercial) : la
                // commande avait déjà crédité une commission (validée avant
                // d'être annulée) — on la reprend, une seule fois.
                $this->reprendreCommissionCommercialSiEligible();
            }

            if ($cible === STATUT_COMMANDE_VALIDEE) {
                $this->update([
                    'coordinateur_id' => $this->coordinateur_id ?? $coordinateurId,
                    'date_validation' => $this->date_validation ?? now(),
                ]);

                $this->crediterCommissionCommercialSiEligible();
            }

            if ($cible === STATUT_COMMANDE_EN_PREPARATION) {
                $this->livraison?->update(['statut_livraison' => STATUT_LIVRAISON_EN_ATTENTE_LIVREUR]);
            }

            if ($cible === STATUT_COMMANDE_EN_LIVRAISON) {
                // 'assignee' (pas 'en_cours' directement) : le livreur doit
                // accepter la mission avant qu'elle ne devienne active — voir
                // LivraisonController::accepter(). date_prise_en_charge n'est
                // posée qu'à ce moment-là, plus ici.
                $this->livraison?->update([
                    'livreur_id' => $livreurId,
                    'statut_livraison' => STATUT_LIVRAISON_ASSIGNEE,
                ]);
            }

            if ($cible === STATUT_COMMANDE_LIVREE) {
                $this->livraison?->update([
                    'statut_livraison' => STATUT_LIVRAISON_LIVREE,
                    'date_livraison_effective' => now(),
                ]);
                Garantie::genererPourCommande($this);
                $this->crediterParrainageSiEligible();
                $this->crediterFournisseursSiEligible();
            }

            $this->update(['statut_commande' => $cible]);
        });
    }

    /**
     * Instantané des infos affichables d'une commande (produit, client,
     * livraison, détail prix, bonus) — même forme que le payload figé dans
     * le message système "commande_creee" (voir CommandeController::
     * publierMessageCommandeCreee()), mais calculé à la demande pour
     * toujours refléter l'état courant (utilisé par show() pour l'écran
     * détail Coordinateur). Suppose `lignes.produit.images`, `client.user`
     * et `livraison.adresse.localite` déjà chargés par l'appelant.
     */
    public function versApercu(): array
    {
        $ligne = $this->lignes->first();
        $produit = $ligne?->produit;
        $adresse = $this->livraison?->adresse;

        return [
            'nom_produit' => $produit?->nom_produit,
            'photo' => $produit?->images->first()?->url_image,
            'prix_produit' => (float) $this->montant_total,
            'nom_client' => trim(($this->client?->user?->prenom ?? '').' '.($this->client?->user?->nom ?? '')),
            'telephone' => $this->client?->user?->telephone,
            'zone_livraison' => $adresse ? ($adresse->localite ? "{$adresse->ville}, {$adresse->localite->nom}" : $adresse->ville) : null,
            'date_livraison_prevue' => $this->livraison?->date_livraison_prevue,
            'frais_livraison' => (float) $this->frais_livraison,
            'remise' => (float) $this->montant_remise,
            'total' => $this->montantNet(),
            'bonus_offerts' => $this->bonus_offerts,
            'notes' => $this->notes,
        ];
    }
}
