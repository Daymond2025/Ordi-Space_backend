<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\Commercial;
use App\Models\LigneCommande;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Commission du commercial, par produit vendu (quantité comprise) :
 * - commercial interne : COMMISSION_COMMERCIAL_INTERNE (1 000 F) ;
 * - commercial indépendant : Produit::commission_commercial_independant,
 *   ou COMMISSION_COMMERCIAL_INDEPENDANT_DEFAUT (2 500 F) si l'Admin ne l'a
 *   pas renseignée.
 *
 * Le montant est figé sur la commande (commandes.commission_commercial) au
 * moment où il est crédité (Commande::crediterCommissionCommercialSiEligible()).
 * Tant qu'il n'est pas figé, il est recalculé à partir des lignes.
 */
class CommissionCommercial
{
    public static function tauxPourProduit(?Commercial $commercial, ?Produit $produit): float
    {
        if ($commercial?->estIndependant()) {
            return (float) ($produit?->commission_commercial_independant ?? COMMISSION_COMMERCIAL_INDEPENDANT_DEFAUT);
        }

        return (float) COMMISSION_COMMERCIAL_INTERNE;
    }

    /** Montant dû pour cette commande, calculé à partir de ses lignes (jamais le montant figé). */
    public static function calculer(Commande $commande, ?Commercial $commercial = null): float
    {
        if (! $commande->commercial_id) {
            return 0.0;
        }

        $commercial ??= $commande->commercial;
        $commande->loadMissing('lignes.produit');

        return round($commande->lignes->sum(
            fn (LigneCommande $ligne) => self::tauxPourProduit($commercial, $ligne->produit) * (int) $ligne->quantite
        ), 2);
    }

    /** Montant figé s'il existe, sinon calculé. */
    public static function montant(Commande $commande, ?Commercial $commercial = null): float
    {
        return $commande->commission_commercial !== null
            ? (float) $commande->commission_commercial
            : self::calculer($commande, $commercial);
    }

    /**
     * Total des commissions des commandes LIVRÉES de la requête donnée
     * (déjà filtrée par commercial et/ou période par l'appelant).
     */
    public static function totalLivrees(Builder|Relation $commandes): float
    {
        $livrees = (clone $commandes)
            ->where('statut_commande', STATUT_COMMANDE_LIVREE)
            ->with(['lignes.produit', 'commercial'])
            ->get();

        return round($livrees->sum(fn (Commande $commande) => self::montant($commande, $commande->commercial)), 2);
    }

    /**
     * Total par commercial des commandes livrées (clé = commercial_id) —
     * pour les listes (Coordinateur, tableau de bord Admin) sans une requête
     * par commercial.
     *
     * @param  iterable<int>  $commercialIds
     * @return array<int, float>
     */
    public static function totauxLivreesParCommercial(iterable $commercialIds): array
    {
        $totaux = [];

        Commande::whereIn('commercial_id', collect($commercialIds)->all())
            ->where('statut_commande', STATUT_COMMANDE_LIVREE)
            ->with(['lignes.produit', 'commercial'])
            ->get()
            ->each(function (Commande $commande) use (&$totaux) {
                $totaux[$commande->commercial_id] = ($totaux[$commande->commercial_id] ?? 0) + self::montant($commande, $commande->commercial);
            });

        return array_map(fn ($total) => round($total, 2), $totaux);
    }
}
