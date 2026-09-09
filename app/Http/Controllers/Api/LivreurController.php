<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Livraison;
use App\Models\Livreur;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liste des livreurs disponibles pour le sélecteur "Envoyer à un livreur"
 * (Espace Coordinateur, écran détail commande) — mêmes permissions que
 * l'assignation elle-même (CommandeController::assignerLivreur()).
 */
class LivreurController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_LIVRAISONS_ASSIGNER), 403);

        $livreurs = User::where('type_utilisateur', ROLE_LIVREUR)
            ->where('statut_compte', STATUT_COMPTE_ACTIF)
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom', 'telephone']);

        return $this->success($livreurs);
    }

    /**
     * Écran "Livreurs" (Centre des opérations, bouton bottombar) — forme
     * enrichie (véhicule, zone, disponibilité), distincte de index() pour ne
     * prendre aucun risque de régression sur le sélecteur "Envoyer à un
     * livreur" qui consomme déjà index() avec une forme différente.
     */
    public function liste(Request $request): JsonResponse
    {
        $livreurs = Livreur::with('user')
            ->whereHas('user', fn ($q) => $q->where('statut_compte', STATUT_COMPTE_ACTIF))
            ->get()
            ->map(fn (Livreur $livreur) => [
                'user_id' => $livreur->user_id,
                'nom' => $livreur->user->nom,
                'prenom' => $livreur->user->prenom,
                'telephone' => $livreur->user->telephone,
                'type_vehicule' => $livreur->type_vehicule,
                'zone_couverture' => $livreur->zone_couverture,
                'disponible' => $livreur->disponible,
            ]);

        return $this->success($livreurs);
    }

    /**
     * Écran détail livreur — profil + statistiques calculées à la volée
     * (aucune colonne dédiée). "commandes_retournees" = livraisons échouées
     * OU signalées pour un retour physique au dépôt (retour_necessaire) ;
     * "gains_total_recu" = cash COD confirmé encaissé par ce livreur
     * (paiementsEncaisses), pas une rémunération réelle ; "gains_non_deposes"
     * = part de ce cash pas encore reversée (Paiement::date_depot).
     */
    public function show(Request $request, Livreur $livreur): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_LIVRAISONS_ASSIGNER), 403);

        $livraisons = $livreur->livraisons();
        $paiementsEncaisses = $livreur->paiementsEncaisses()->where('statut_paiement', STATUT_PAIEMENT_CONFIRME);

        return $this->success([
            'user_id' => $livreur->user_id,
            'nom' => $livreur->user->nom,
            'prenom' => $livreur->user->prenom,
            'telephone' => $livreur->user->telephone,
            'disponible' => $livreur->disponible,
            'statistiques' => [
                'commandes_total' => (clone $livraisons)->count(),
                'commandes_livrees' => (clone $livraisons)->where('statut_livraison', STATUT_LIVRAISON_LIVREE)->count(),
                'commandes_retournees' => (clone $livraisons)
                    ->where(fn ($q) => $q->where('statut_livraison', STATUT_LIVRAISON_ECHOUEE)->orWhere('retour_necessaire', true))
                    ->count(),
                'gains_total_recu' => (clone $paiementsEncaisses)->sum('montant'),
                'gains_non_deposes' => (clone $paiementsEncaisses)->whereNull('date_depot')->sum('montant'),
            ],
        ]);
    }

    /**
     * "Les Missions" — historique complet (en cours + terminées) des
     * livraisons assignées à ce livreur, triées pour que la première mission
     * "en_cours" soit la mission active (miroir de l'écran livreur, voir
     * EcranMissionsLivreur.tsx).
     */
    public function missions(Request $request, Livreur $livreur): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_LIVRAISONS_ASSIGNER), 403);

        $missions = $livreur->livraisons()
            ->with(['commande.lignes.produit.fournisseur', 'commande.client.user', 'commande.paiement', 'adresse.localite'])
            ->orderByRaw('date_prise_en_charge IS NULL, date_prise_en_charge ASC')
            ->get()
            ->map(function (Livraison $livraison) {
                $paiement = $livraison->commande->paiement;
                $produit = $livraison->commande->lignes->first()?->produit;

                return [
                    'commande_id' => $livraison->commande_id,
                    'nom_produit' => $produit?->nom_produit,
                    'nom_client' => trim(($livraison->commande->client?->user?->prenom ?? '').' '.($livraison->commande->client?->user?->nom ?? '')),
                    // Coordonnée "récupération" tant que la mission n'est pas
                    // acceptée (le livreur doit d'abord passer chez le
                    // fournisseur) — voir EcranMissionsLivreur.tsx.
                    'nom_fournisseur' => $produit?->fournisseur?->nom_entreprise,
                    'zone_destination' => $livraison->adresse->localite->nom ?? null,
                    'statut_commande' => $livraison->commande->statut_commande,
                    'statut_livraison' => $livraison->statut_livraison,
                    'retour_necessaire' => $livraison->retour_necessaire,
                    'statut_retour' => $livraison->statut_retour,
                    'frais_livraison' => $livraison->commande->frais_livraison,
                    'date_livraison_prevue' => $livraison->date_livraison_prevue,
                    'date_livraison_effective' => $livraison->date_livraison_effective,
                    'paiement' => $paiement ? [
                        'mode_paiement' => $paiement->mode_paiement,
                        'date_limite_depot' => $paiement->date_limite_depot,
                        'date_depot' => $paiement->date_depot,
                    ] : null,
                ];
            });

        return $this->success($missions);
    }

    /**
     * Vivier des livraisons non affectées ("Assigner une nouvelle mission")
     * — même concept que le vivier consommé côté app livreur
     * (LivraisonController::index(), non modifié ici). Filtrable par
     * fournisseur (menu déroulant de l'écran) : le fournisseur "concerné"
     * est celui du produit de la première ligne, même simplification que
     * nom_produit ailleurs sur cet écran (pas de vraie notion multi-
     * fournisseur par commande).
     */
    public function livraisonsDisponibles(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_LIVRAISONS_ASSIGNER), 403);

        $fournisseurId = $request->integer('fournisseur_id') ?: null;

        $livraisons = Livraison::where('statut_livraison', STATUT_LIVRAISON_EN_ATTENTE_LIVREUR)
            ->whereNull('livreur_id')
            ->when($fournisseurId, fn ($q) => $q->whereHas(
                'commande.lignes.produit',
                fn ($q2) => $q2->where('fournisseur_id', $fournisseurId)
            ))
            ->with(['commande.lignes.produit.fournisseur', 'commande.lignes.produit.images', 'adresse.localite'])
            ->latest('id')
            ->get()
            ->map(function (Livraison $livraison) {
                $produit = $livraison->commande->lignes->first()?->produit;

                return [
                    'commande_id' => $livraison->commande_id,
                    'nom_produit' => $produit?->nom_produit,
                    'photo' => $produit?->images->first()?->url_image,
                    'zone_depart' => $produit?->fournisseur?->zone_couverte,
                    'zone_destination' => $livraison->adresse->localite->nom ?? null,
                    'frais_livraison' => $livraison->commande->frais_livraison,
                ];
            });

        return $this->success($livraisons);
    }
}
