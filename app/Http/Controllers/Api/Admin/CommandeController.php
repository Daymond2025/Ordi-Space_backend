<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Coordinateur;
use App\Models\Garantie;
use App\Models\JournalAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $commande->loadMissing('livraison', 'lignes.produit');

        DB::transaction(function () use ($commande, $nouveauStatut, $data, $request) {
            if ($nouveauStatut === STATUT_COMMANDE_ANNULEE && $commande->statut_commande !== STATUT_COMMANDE_ANNULEE) {
                foreach ($commande->lignes as $ligne) {
                    $ligne->produit?->increment('quantite_stock', $ligne->quantite);
                }
            }

            if ($nouveauStatut === STATUT_COMMANDE_VALIDEE) {
                // coordinateur_id référence la table coordinateurs : on ne
                // l'attribue à l'admin que s'il est lui-même coordinateur,
                // sinon on le laisse tel quel (override sans acteur assigné).
                $adminEstCoordinateur = Coordinateur::where('user_id', $request->user()->id)->exists();

                $commande->update([
                    'coordinateur_id' => $commande->coordinateur_id ?? ($adminEstCoordinateur ? $request->user()->id : null),
                    'date_validation' => $commande->date_validation ?? now(),
                ]);
            }

            if ($nouveauStatut === STATUT_COMMANDE_EN_PREPARATION) {
                $commande->livraison?->update(['statut_livraison' => STATUT_LIVRAISON_EN_ATTENTE_LIVREUR]);
            }

            if ($nouveauStatut === STATUT_COMMANDE_EN_LIVRAISON) {
                $commande->livraison?->update([
                    'livreur_id' => $data['livreur_id'],
                    'statut_livraison' => STATUT_LIVRAISON_EN_COURS,
                    'date_prise_en_charge' => now(),
                ]);
            }

            if ($nouveauStatut === STATUT_COMMANDE_LIVREE) {
                $commande->livraison?->update([
                    'statut_livraison' => STATUT_LIVRAISON_LIVREE,
                    'date_livraison_effective' => now(),
                ]);
                Garantie::genererPourCommande($commande);
                $commande->crediterParrainageSiEligible();
            }

            $commande->update(['statut_commande' => $nouveauStatut]);
        });

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Statut de la commande n°{$commande->id} changé à « {$nouveauStatut} » par un administrateur."
        );

        return $this->success($commande->fresh(['livraison.livreur.user', 'lignes.garantie']));
    }
}
