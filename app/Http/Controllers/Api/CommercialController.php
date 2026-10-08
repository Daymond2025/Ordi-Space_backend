<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Commercial;
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
     * "commission_totale" = COMMISSION_COMMERCIAL_PAR_VENTE × commandes
     * livrées (montant fixe, confirmé PDG — jamais Produit::commission_agent,
     * un champ du formulaire Coordinateur sans lien avec ce que touche
     * réellement l'agent commercial).
     */
    public function liste(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_CONSULTER), 403);

        $commandesCommercial = fn () => Commande::selectRaw('count(*)')->whereColumn('commercial_id', 'commerciaux.user_id');

        $commerciaux = Commercial::with('user')
            ->where('type_commercial', TYPE_COMMERCIAL_HUMAIN)
            ->addSelect([
                'commandes_total_count' => $commandesCommercial(),
                'commandes_validees_count' => $commandesCommercial()->where('commandes.statut_commande', STATUT_COMMANDE_VALIDEE),
                'commandes_annulees_count' => $commandesCommercial()->where('commandes.statut_commande', STATUT_COMMANDE_ANNULEE),
                'commandes_livrees_count' => $commandesCommercial()->where('commandes.statut_commande', STATUT_COMMANDE_LIVREE),
            ])
            ->withCasts([
                'commandes_total_count' => 'integer',
                'commandes_validees_count' => 'integer',
                'commandes_annulees_count' => 'integer',
                'commandes_livrees_count' => 'integer',
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
                'commission_totale' => (float) ($commercial->commandes_livrees_count * COMMISSION_COMMERCIAL_PAR_VENTE),
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
        $commandesLivrees = (clone $commandes)->where('statut_commande', STATUT_COMMANDE_LIVREE)->count();

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
                'commission_totale' => (float) ($commandesLivrees * COMMISSION_COMMERCIAL_PAR_VENTE),
            ],
        ]);
    }

    /**
     * Historique des commandes saisies par CE commercial — fiche Admin/
     * Coordinateur ("aucun détail ne doit échapper à l'admin"), les stats de
     * show() ne donnant que des compteurs, jamais la liste elle-même.
     */
    public function commandes(Request $request, Commercial $commercial): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_CONSULTER), 403);

        $commandes = $commercial->commandes()
            ->with(['client.user', 'lignes.produit'])
            ->latest('date_commande')
            ->paginate(paginate_per_page($request))
            ->through(fn (Commande $commande) => [
                'commande_id' => $commande->id,
                'nom_produit' => $commande->lignes->first()?->produit?->nom_produit,
                'nom_client' => trim(($commande->client?->user?->prenom ?? '').' '.($commande->client?->user?->nom ?? '')),
                'statut' => $commande->statut_commande,
                'montant_total' => (float) $commande->montant_total,
                'date_commande' => $commande->date_commande,
            ]);

        return $this->success($commandes);
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
