<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Coordinateur;
use App\Models\JournalAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommandeController extends Controller
{
    /**
     * Fiche complète d'une commande pour la page "Détails commande" de
     * l'admin : produit, statut, paiement et les 3 acteurs du parcours
     * (commercial, fournisseur(s), livreur).
     */
    public function show(Commande $commande): JsonResponse
    {
        $commande->load([
            'client.user',
            'commercial.user',
            'coordinateur.user',
            'canalVente',
            'lignes.produit.images',
            'lignes.produit.categorie',
            'lignes.produit.fournisseur.user',
            'lignes.garantie',
            'livraison.livreur.user',
            'livraison.adresse',
            'paiement',
            'privilege',
            'parrain.user',
        ]);

        return $this->success($commande);
    }

    /**
     * Override admin : force une transition de statut sans passer par
     * l'acteur normalement responsable (coordinateur/fournisseur/livreur).
     * Utile pour débloquer une commande quand cet acteur n'a pas agi.
     * Rejoue les mêmes effets de bord que le flux normal (génération de
     * garantie, crédit de parrainage, restockage à l'annulation) pour ne
     * pas laisser les données incohérentes.
     */
    public function changerStatut(Request $request, Commande $commande): JsonResponse
    {
        $data = $request->validate([
            'statut_commande' => ['required', Rule::in([
                STATUT_COMMANDE_EN_ATTENTE,
                STATUT_COMMANDE_VALIDEE,
                STATUT_COMMANDE_EN_PREPARATION,
                STATUT_COMMANDE_EN_LIVRAISON,
                STATUT_COMMANDE_LIVREE,
                STATUT_COMMANDE_ANNULEE,
            ])],
            'livreur_id' => ['required_if:statut_commande,'.STATUT_COMMANDE_EN_LIVRAISON, 'nullable', 'exists:livreurs,user_id'],
        ]);

        $nouveauStatut = $data['statut_commande'];

        // coordinateur_id référence la table coordinateurs : on ne l'attribue
        // à l'admin que s'il est lui-même coordinateur, sinon on le laisse
        // tel quel (override sans acteur assigné).
        $adminEstCoordinateur = Coordinateur::where('user_id', $request->user()->id)->exists();

        $commande->appliquerChangementStatut(
            $nouveauStatut,
            livreurId: $data['livreur_id'] ?? null,
            coordinateurId: $adminEstCoordinateur ? $request->user()->id : null,
        );

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Statut de la commande n°{$commande->id} changé à « {$nouveauStatut} » par un administrateur.",
            commandeId: $commande->id,
            donnees: ['statut_apres' => $nouveauStatut],
        );

        return $this->success($commande->fresh(['livraison.livreur.user', 'lignes.garantie']));
    }
}
