<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\Commande;
use App\Models\DemandeRetrait;
use App\Models\DemandeSav;
use App\Models\Fournisseur;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\Reclamation;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class StatistiqueController extends Controller
{
    public function globales(): JsonResponse
    {
        return $this->success([
            'utilisateurs_par_type' => User::selectRaw('type_utilisateur, count(*) as total')
                ->groupBy('type_utilisateur')->pluck('total', 'type_utilisateur'),
            'commandes_par_statut' => Commande::selectRaw('statut_commande, count(*) as total')
                ->groupBy('statut_commande')->pluck('total', 'statut_commande'),
            'produits_par_statut' => Produit::selectRaw('statut_produit, count(*) as total')
                ->groupBy('statut_produit')->pluck('total', 'statut_produit'),
            'chiffre_affaires_encaisse' => Paiement::where('statut_paiement', STATUT_PAIEMENT_CONFIRME)->sum('montant'),
        ]);
    }

    /**
     * Dashboard général (page d'accueil Admin) — l'admin est "l'œil central"
     * du système : une vue d'ensemble de TOUTES les entités en un seul appel,
     * plus une file "à traiter maintenant" qui agrège les 4 files d'attente
     * déjà gérées séparément ailleurs (produits à valider, réclamations,
     * pannes SAV, retraits livreurs) pour qu'aucune ne passe inaperçue.
     * Chiffres bruts (compteurs/sommes), jamais de liste paginée ici — les
     * écrans dédiés de chaque section restent la source pour le détail.
     */
    public function tableauDeBordGeneral(): JsonResponse
    {
        $debutJour = now()->startOfDay();
        $debutMois = now()->startOfMonth();
        $septJoursGlissants = now()->subDays(6)->startOfDay();

        $inscriptionsParJour = User::where('created_at', '>=', $septJoursGlissants)
            ->selectRaw('DATE(created_at) as jour, count(*) as total')
            ->groupBy('jour')
            ->pluck('total', 'jour');

        $inscriptions_7j = collect(range(0, 6))->map(function (int $i) use ($inscriptionsParJour) {
            $jour = now()->subDays(6 - $i);
            return ['jour' => $jour->toDateString(), 'total' => (int) ($inscriptionsParJour[$jour->toDateString()] ?? 0)];
        });

        return $this->success([
            'utilisateurs' => [
                'total' => User::count(),
                'clients' => User::where('type_utilisateur', ROLE_CLIENT)->count(),
                'fournisseurs' => User::where('type_utilisateur', ROLE_FOURNISSEUR)->count(),
                'livreurs' => User::where('type_utilisateur', ROLE_LIVREUR)->count(),
                'coordinateurs' => User::where('type_utilisateur', ROLE_COORDINATEUR)->count(),
                'commerciaux' => User::where('type_utilisateur', ROLE_COMMERCIAL)->count(),
                'nouveaux_7j' => User::where('created_at', '>=', $septJoursGlissants)->count(),
            ],
            'commandes' => [
                'total' => Commande::count(),
                'aujourd_hui' => Commande::where('date_commande', '>=', $debutJour)->count(),
                'en_attente' => Commande::where('statut_commande', STATUT_COMMANDE_EN_ATTENTE)->count(),
                'en_cours' => Commande::whereIn('statut_commande', [
                    STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_EN_PREPARATION, STATUT_COMMANDE_EN_LIVRAISON,
                ])->count(),
                'livrees' => Commande::where('statut_commande', STATUT_COMMANDE_LIVREE)->count(),
                'annulees' => Commande::where('statut_commande', STATUT_COMMANDE_ANNULEE)->count(),
            ],
            'finance' => [
                'chiffre_affaires_total' => (float) Paiement::where('statut_paiement', STATUT_PAIEMENT_CONFIRME)->sum('montant'),
                'chiffre_affaires_mois' => (float) Paiement::where('statut_paiement', STATUT_PAIEMENT_CONFIRME)
                    ->where('date_paiement', '>=', $debutMois)->sum('montant'),
                'fournisseurs_solde_du' => (float) Fournisseur::sum('solde_portefeuille'),
                'retraits_en_attente_montant' => (float) DemandeRetrait::where('statut', STATUT_RETRAIT_EN_ATTENTE)->sum('montant'),
            ],
            'a_traiter' => [
                'produits_a_valider' => Produit::where('statut_produit', STATUT_PRODUIT_EN_ATTENTE)->count(),
                'reclamations_nouvelles' => Reclamation::where('statut', STATUT_RECLAMATION_NOUVELLE)->count(),
                'pannes_en_attente' => DemandeSav::where('statut_demande', STATUT_DEMANDE_SAV_EN_ATTENTE)->count(),
                'retraits_en_attente' => DemandeRetrait::where('statut', STATUT_RETRAIT_EN_ATTENTE)->count(),
            ],
            'catalogue' => [
                'total' => Produit::count(),
                'valides' => Produit::where('statut_produit', STATUT_PRODUIT_VALIDE)->count(),
                'stock_faible' => Produit::where('quantite_stock', '>', 0)->where('quantite_stock', '<=', 5)->count(),
                'indisponibles' => Produit::where('quantite_stock', '<=', 0)->count(),
                'categories' => Categorie::count(),
            ],
            'inscriptions_7j' => $inscriptions_7j,
        ]);
    }
}
