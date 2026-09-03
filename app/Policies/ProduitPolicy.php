<?php

namespace App\Policies;

use App\Models\Produit;
use App\Models\User;

class ProduitPolicy
{
    public function update(User $user, Produit $produit): bool
    {
        if (! $user->can(PERMISSION_PRODUITS_MODIFIER)) {
            return false;
        }

        // Accessoire/logiciel publié directement par l'Admin : pas de cycle
        // de validation, il peut le modifier à tout moment.
        if ($user->type_utilisateur === ROLE_ADMINISTRATEUR) {
            return $produit->fournisseur_id === null;
        }

        return $produit->fournisseur_id === $user->id
            && in_array($produit->statut_produit, [STATUT_PRODUIT_EN_ATTENTE, STATUT_PRODUIT_REJETE, STATUT_PRODUIT_CORRIGE], true);
    }

    /**
     * Gestion étroite du stock/disponibilité (ProduitController::modifierStock) —
     * périmètre plus large que update() : le Coordinateur peut agir sur
     * n'importe quel produit (pas seulement en_attente/rejete/corrige),
     * le Fournisseur uniquement sur le sien.
     */
    public function gererStock(User $user, Produit $produit): bool
    {
        if (! $user->can(PERMISSION_PRODUITS_GERER_STOCK)) {
            return false;
        }

        if (in_array($user->type_utilisateur, [ROLE_COORDINATEUR, ROLE_ADMINISTRATEUR], true)) {
            return true;
        }

        return $produit->fournisseur_id === $user->id;
    }

    public function valider(User $user, Produit $produit): bool
    {
        return $user->can(PERMISSION_PRODUITS_VALIDER) && $produit->statut_produit !== STATUT_PRODUIT_VALIDE;
    }

    /**
     * Ajustement du prix (ProduitController::modifierPrix) — même acteurs que
     * valider(), mais sans restriction de statut : le coordinateur peut
     * corriger un prix aussi bien pendant la revue qu'après publication.
     */
    public function modifierPrix(User $user, Produit $produit): bool
    {
        return $user->can(PERMISSION_PRODUITS_VALIDER);
    }

    public function delete(User $user, Produit $produit): bool
    {
        return $user->can(PERMISSION_PRODUITS_SUPPRIMER);
    }

    /**
     * Correctifs de fiche (nom, description, catégorie, caractéristiques,
     * cadeaux — ProduitController::modifierFiche()) par le coordinateur sur
     * un produit qui ne lui appartient pas — délibérément séparée de
     * update() pour ne jamais donner accès à la lourde route de
     * remplacement complet (prix/stock inclus) réservée au fournisseur.
     */
    public function modifierFiche(User $user, Produit $produit): bool
    {
        return $user->can(PERMISSION_PRODUITS_VALIDER);
    }
}
