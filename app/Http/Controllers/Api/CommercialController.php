<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Commercial;
use App\Models\LigneCommande;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Écran "Les commerciaux" (Espace Agent, bottombar) — Espace Coordinateur.
 */
class CommercialController extends Controller
{
    /**
     * Statistiques calculées par sous-requête corrélée (même technique que
     * FournisseurController::index()) — pas de colonnes dédiées.
     * "commission_totale" réutilise Produit::commission_agent (posé à la
     * publication, cf. ProduitController::publier()) : c'est littéralement
     * ce que touche l'agent commercial sur ce produit, sommé sur ses
     * commandes livrées — donnée réelle, pas un nouveau concept fabriqué.
     */
    public function liste(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_CONSULTER), 403);

        $commandesCommercial = fn () => Commande::selectRaw('count(*)')->whereColumn('commercial_id', 'commerciaux.user_id');

        $commissionTotale = fn () => LigneCommande::selectRaw('COALESCE(SUM(produits.commission_agent), 0)')
            ->join('commandes', 'commandes.id', '=', 'lignes_commande.commande_id')
            ->join('produits', 'produits.id', '=', 'lignes_commande.produit_id')
            ->whereColumn('commandes.commercial_id', 'commerciaux.user_id')
            ->where('commandes.statut_commande', STATUT_COMMANDE_LIVREE);

        $commerciaux = Commercial::with('user')
            ->where('type_commercial', TYPE_COMMERCIAL_HUMAIN)
            ->addSelect([
                'commandes_total_count' => $commandesCommercial(),
                'commandes_validees_count' => $commandesCommercial()->where('commandes.statut_commande', STATUT_COMMANDE_VALIDEE),
                'commandes_annulees_count' => $commandesCommercial()->where('commandes.statut_commande', STATUT_COMMANDE_ANNULEE),
                'commission_totale' => $commissionTotale(),
            ])
            ->withCasts([
                'commandes_total_count' => 'integer',
                'commandes_validees_count' => 'integer',
                'commandes_annulees_count' => 'integer',
                'commission_totale' => 'float',
            ])
            ->get()
            ->map(fn (Commercial $commercial) => [
                'user_id' => $commercial->user_id,
                'nom' => $commercial->user->nom,
                'prenom' => $commercial->user->prenom,
                'actif' => $commercial->user->statut_compte === STATUT_COMPTE_ACTIF,
                'membre_depuis' => $commercial->created_at,
                'commandes_total' => $commercial->commandes_total_count,
                'commandes_validees' => $commercial->commandes_validees_count,
                'commandes_annulees' => $commercial->commandes_annulees_count,
                'commission_totale' => $commercial->commission_totale,
            ]);

        return $this->success($commerciaux);
    }

    /**
     * Écran "Profil commercial" — ouvert au tap sur l'avatar d'une carte de
     * l'écran "Les commerciaux". Un seul commercial : pas besoin de la
     * sous-requête corrélée de liste(), des comptages directs suffisent.
     */
    public function show(Request $request, Commercial $commercial): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_CONSULTER), 403);

        $commandes = $commercial->commandes();

        $commissionTotale = LigneCommande::selectRaw('COALESCE(SUM(produits.commission_agent), 0) as total')
            ->join('commandes', 'commandes.id', '=', 'lignes_commande.commande_id')
            ->join('produits', 'produits.id', '=', 'lignes_commande.produit_id')
            ->where('commandes.commercial_id', $commercial->user_id)
            ->where('commandes.statut_commande', STATUT_COMMANDE_LIVREE)
            ->value('total');

        return $this->success([
            'user_id' => $commercial->user_id,
            'nom' => $commercial->user->nom,
            'prenom' => $commercial->user->prenom,
            'telephone' => $commercial->user->telephone,
            'actif' => $commercial->user->statut_compte === STATUT_COMPTE_ACTIF,
            'nom_entreprise' => $commercial->nom_entreprise,
            'localisation' => $commercial->localisation,
            'statistiques' => [
                'commandes_total' => (clone $commandes)->count(),
                'commandes_validees' => (clone $commandes)->where('statut_commande', STATUT_COMMANDE_VALIDEE)->count(),
                'commandes_annulees' => (clone $commandes)->where('statut_commande', STATUT_COMMANDE_ANNULEE)->count(),
                'commission_totale' => (float) $commissionTotale,
            ],
        ]);
    }

    /**
     * Active/suspend le compte du commercial — action de gestion d'équipe du
     * coordinateur, à la différence du toggle "En ligne" du livreur (lecture
     * seule, reflète un statut que seul le livreur contrôle).
     */
    public function changerStatut(Request $request, Commercial $commercial): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_GERER), 403);

        $data = $request->validate(['actif' => ['required', 'boolean']]);

        $commercial->user->update([
            'statut_compte' => $data['actif'] ? STATUT_COMPTE_ACTIF : STATUT_COMPTE_SUSPENDU,
        ]);

        return $this->success(['actif' => $data['actif']]);
    }
}
