<?php

namespace App\Http\Controllers\Api\Coordinateur;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\TransactionPortefeuilleFournisseur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "Space" — accueil de l'Espace Coordinateur : compteurs de commandes et de
 * commissions fournisseur par statut, filtrés par période. L'activité
 * récente des produits vit dans MessageController::produitsActifs().
 */
class EspaceController extends Controller
{
    public function statistiques(Request $request): JsonResponse
    {
        [$debut, $fin] = $this->resoudrePeriode($request);
        $periodeDefinie = $debut && $fin;

        $base = Commande::query()->when($periodeDefinie, fn ($q) => $q->whereBetween('date_commande', [$debut, $fin]));

        $credits = TransactionPortefeuilleFournisseur::where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)
            ->when($periodeDefinie, fn ($q) => $q->whereBetween('date_transaction', [$debut, $fin]));
        $debits = TransactionPortefeuilleFournisseur::where('type', TYPE_TRANSACTION_PORTEFEUILLE_DEBIT)
            ->when($periodeDefinie, fn ($q) => $q->whereBetween('date_transaction', [$debut, $fin]));

        return $this->success([
            'periode' => ['debut' => $debut?->toDateTimeString(), 'fin' => $fin?->toDateTimeString()],
            'commandes_recues' => (clone $base)->count(),
            'commandes_validees' => (clone $base)->whereNotNull('coordinateur_id')->count(),
            'commandes_en_attente' => (clone $base)->where('statut_commande', STATUT_COMMANDE_EN_ATTENTE)->count(),
            // Large : ni livrée ni annulée (regroupe validée/en_préparation/en_livraison
            // et les statuts "problème") — même définition que Produit::statistiquesCommandes().
            'commandes_en_cours' => (clone $base)->whereNotIn('statut_commande', [STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_ANNULEE])->count(),
            'commandes_en_livraison' => (clone $base)->where('statut_commande', STATUT_COMMANDE_EN_LIVRAISON)->count(),
            'commandes_livrees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_LIVREE)->count(),
            'commandes_annulees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_ANNULEE)->count(),
            'commandes_reportees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_REPORTEE)->count(),
            'commandes_client_injoignable' => (clone $base)->where('statut_commande', STATUT_COMMANDE_CLIENT_INJOIGNABLE)->count(),
            'commandes_numero_incorrect' => (clone $base)->where('statut_commande', STATUT_COMMANDE_NUMERO_INCORRECT)->count(),
            'commission_generee' => (float) (clone $credits)->sum('commission_prelevee'),
            'commission_distribuee' => (float) (clone $debits)->sum('montant'),
        ]);
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resoudrePeriode(Request $request): array
    {
        return resoudre_periode($request);
    }
}
