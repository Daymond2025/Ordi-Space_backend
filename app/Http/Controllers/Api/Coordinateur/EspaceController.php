<?php

namespace App\Http\Controllers\Api\Coordinateur;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "Space" — accueil de l'Espace Coordinateur. Phase 1 : compteurs de
 * commandes par statut, filtrés par période. La commission (générée et
 * distribuée) et l'activité récente des produits (nécessite le système de
 * conversation) sont volontairement hors périmètre — phases ultérieures.
 */
class EspaceController extends Controller
{
    public function statistiques(Request $request): JsonResponse
    {
        [$debut, $fin] = $this->resoudrePeriode($request);
        $base = Commande::whereBetween('date_commande', [$debut, $fin]);

        return $this->success([
            'periode' => ['debut' => $debut->toDateTimeString(), 'fin' => $fin->toDateTimeString()],
            'commandes_recues' => (clone $base)->count(),
            'commandes_en_attente' => (clone $base)->where('statut_commande', STATUT_COMMANDE_EN_ATTENTE)->count(),
            'commandes_en_livraison' => (clone $base)->where('statut_commande', STATUT_COMMANDE_EN_LIVRAISON)->count(),
            'commandes_livrees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_LIVREE)->count(),
            'commandes_annulees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_ANNULEE)->count(),
            'commandes_reportees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_REPORTEE)->count(),
            'commandes_client_injoignable' => (clone $base)->where('statut_commande', STATUT_COMMANDE_CLIENT_INJOIGNABLE)->count(),
            'commandes_numero_incorrect' => (clone $base)->where('statut_commande', STATUT_COMMANDE_NUMERO_INCORRECT)->count(),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resoudrePeriode(Request $request): array
    {
        if ($request->filled('date_debut') && $request->filled('date_fin')) {
            return [
                Carbon::parse($request->string('date_debut'))->startOfDay(),
                Carbon::parse($request->string('date_fin'))->endOfDay(),
            ];
        }

        return match ($request->string('periode')->toString() ?: 'aujourd_hui') {
            'semaine' => [now()->startOfWeek(), now()->endOfWeek()],
            'semaine_derniere' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'mois' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }
}
