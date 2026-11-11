<?php

namespace App\Services\Partenaire;

use App\Models\Partenaire;
use App\Models\Produit;
use App\Models\TarifPartenaire;
use Illuminate\Support\Collection;

/**
 * Prix de cession d'un produit pour un partenaire = tarif d'exception s'il
 * existe, sinon prix Ordi'Space − remise par défaut du partenaire. Le prix
 * Ordi'Space est le prix public (prix_vente, à défaut prix — même règle que
 * CommandeController::store()). Montants arrondis au franc.
 */
class TarificationPartenaire
{
    public static function prixOrdispace(Produit $produit): float
    {
        return (float) ($produit->prix_vente ?? $produit->prix);
    }

    public static function prixCession(Partenaire $partenaire, Produit $produit): float
    {
        $exception = TarifPartenaire::where('partenaire_id', $partenaire->user_id)->where('produit_id', $produit->id)->value('prix_cession');

        return $exception !== null ? (float) $exception : self::appliquerRemise($partenaire, $produit);
    }

    /**
     * Version groupée (listes paginées) : une seule requête pour les
     * exceptions. Retourne [produit_id => prix de cession].
     *
     * @param  Collection<int, Produit>  $produits
     * @return array<int, float>
     */
    public static function prixCessionPour(Partenaire $partenaire, Collection $produits): array
    {
        $exceptions = TarifPartenaire::where('partenaire_id', $partenaire->user_id)
            ->whereIn('produit_id', $produits->pluck('id'))
            ->pluck('prix_cession', 'produit_id');

        return $produits->mapWithKeys(fn (Produit $p) => [
            $p->id => isset($exceptions[$p->id]) ? (float) $exceptions[$p->id] : self::appliquerRemise($partenaire, $p),
        ])->all();
    }

    private static function appliquerRemise(Partenaire $partenaire, Produit $produit): float
    {
        return round(self::prixOrdispace($produit) * (1 - (float) $partenaire->remise_defaut / 100));
    }
}
