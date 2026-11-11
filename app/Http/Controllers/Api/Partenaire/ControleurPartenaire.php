<?php

namespace App\Http\Controllers\Api\Partenaire;

use App\Http\Controllers\Controller;
use App\Models\Partenaire;
use App\Models\Produit;
use Illuminate\Http\Request;

/**
 * Base de l'espace Partenaire : chaque action part du partenaire du jeton
 * courant, jamais d'un identifiant fourni par le client HTTP.
 */
abstract class ControleurPartenaire extends Controller
{
    protected function partenaire(Request $request): Partenaire
    {
        $partenaire = Partenaire::find($request->user()->id);
        abort_unless($partenaire !== null, 403, 'Profil partenaire introuvable.');

        return $partenaire;
    }

    /**
     * Partenaire dont on consulte le suivi : celui du jeton pour un
     * partenaire, celui de l'URL (coordinateur/…/partenaires/{partenaire})
     * pour le coordinateur/admin — un partenaire ne peut jamais viser un autre.
     */
    protected function partenaireCible(Request $request, ?Partenaire $depuisUrl): Partenaire
    {
        if ($request->user()->type_utilisateur === ROLE_PARTENAIRE) {
            return $this->partenaire($request);
        }

        abort_unless($depuisUrl && in_array($request->user()->type_utilisateur, [ROLE_COORDINATEUR, ROLE_ADMINISTRATEUR], true), 403);

        return $depuisUrl;
    }

    /**
     * Fiche produit vue par un partenaire : jamais le prix fournisseur
     * (`prix`) ni les commissions Ordi'Space (commission_ordispace…).
     */
    public static function presenterProduit(Produit $produit): array
    {
        return [
            'id' => $produit->id,
            'nom_produit' => $produit->nom_produit,
            'marque' => $produit->marque,
            'categorie' => $produit->categorie?->nom_categorie,
            'etat_produit' => $produit->etat_produit,
            'image' => $produit->images->first()?->url_image,
            'duree_garantie_mois' => $produit->duree_garantie_mois,
            // Cartes du catalogue (mêmes puces que la marketplace client).
            'processeur' => $produit->processeur,
            'memoire_ram' => $produit->memoire_ram,
            'stockage' => $produit->stockage,
            'taille' => $produit->taille,
            'prix_barre' => $produit->prix_barre !== null ? (float) $produit->prix_barre : null,
            'pourcentage_reduction' => $produit->pourcentage_reduction,
        ];
    }

    protected static function caracteristiques(Produit $produit): array
    {
        return [
            ...self::presenterProduit($produit),
            'description' => $produit->description,
            'images' => $produit->images->pluck('url_image')->all(),
            'processeur' => $produit->processeur,
            'memoire_ram' => $produit->memoire_ram,
            'stockage' => $produit->stockage,
            'taille' => $produit->taille,
            'systeme_exploitation' => $produit->systeme_exploitation,
            'carte_graphique' => $produit->carte_graphique,
            'couleur' => $produit->couleur,
            // Onglets "Les cadeaux" / "Pack complet" de la fiche (comme la marketplace client).
            'cadeaux' => $produit->cadeaux ?? [],
            'contenu_pack' => $produit->contenu_pack ?? [],
        ];
    }
}
