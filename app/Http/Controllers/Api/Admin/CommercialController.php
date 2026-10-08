<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Commercial;
use App\Models\DemandeRetrait;
use App\Models\TransactionPortefeuilleCommercial;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * KPIs agrégés pour le "Tableau de bord" de l'espace Commerciaux — même
 * esprit qu'Admin\LivreurController::tableauDeBord() (comptes, inscriptions
 * par semaine, répartitions), complété par les indicateurs propres au
 * métier de commercial (humain uniquement — un agent IA ne figure pas dans
 * cette vue "équipe", voir CommercialController::liste()) : pipeline des
 * commandes saisies, commissions (créditées/reprises/retirées) et demandes
 * de retrait.
 */
class CommercialController extends Controller
{
    public function tableauDeBord(): JsonResponse
    {
        $idsHumains = Commercial::where('type_commercial', TYPE_COMMERCIAL_HUMAIN)->pluck('user_id');

        $comptes = User::query()->where('type_utilisateur', ROLE_COMMERCIAL)
            ->whereIn('id', $idsHumains)
            ->get(['id', 'created_at', 'statut_compte']);

        $inscriptionsParSemaine = [];
        for ($i = 7; $i >= 0; $i--) {
            $debut = now()->subWeeks($i)->startOfWeek();
            $fin = now()->subWeeks($i)->endOfWeek();
            $inscriptionsParSemaine[] = [
                'semaine' => $debut->format('d/m'),
                'total' => $comptes->whereBetween('created_at', [$debut, $fin])->count(),
            ];
        }

        $commandesParStatut = Commande::whereIn('commercial_id', $idsHumains)
            ->selectRaw('statut_commande, count(*) as total')->groupBy('statut_commande')->pluck('total', 'statut_commande');

        $statutsRetrait = [STATUT_RETRAIT_EN_ATTENTE, STATUT_RETRAIT_VALIDE, STATUT_RETRAIT_REFUSE, STATUT_RETRAIT_ANNULE];
        $retraitsParStatut = [];
        foreach ($statutsRetrait as $statut) {
            $requete = DemandeRetrait::whereIn('user_id', $idsHumains)->where('statut', $statut);
            $retraitsParStatut[$statut] = ['nombre' => (clone $requete)->count(), 'montant' => (float) $requete->sum('montant')];
        }

        $commandesCommercial = fn () => Commande::selectRaw('count(*)')->whereColumn('commercial_id', 'commerciaux.user_id')
            ->where('commandes.statut_commande', STATUT_COMMANDE_LIVREE);

        $topCommerciaux = Commercial::with('user:id,nom,prenom')
            ->where('type_commercial', TYPE_COMMERCIAL_HUMAIN)
            ->addSelect(['commandes_livrees_count' => $commandesCommercial()])
            ->withCasts(['commandes_livrees_count' => 'integer'])
            ->get()
            ->map(fn (Commercial $c) => [
                'user_id' => $c->user_id,
                'nom' => $c->user->nom,
                'prenom' => $c->user->prenom,
                'commission_totale' => (float) ($c->commandes_livrees_count * COMMISSION_COMMERCIAL_PAR_VENTE),
            ])
            ->sortByDesc('commission_totale')
            ->take(5)
            ->values();

        return $this->success([
            'commerciaux' => [
                'total' => $comptes->count(),
                'actifs' => $comptes->where('statut_compte', STATUT_COMPTE_ACTIF)->count(),
                'suspendus' => $comptes->where('statut_compte', STATUT_COMPTE_SUSPENDU)->count(),
                'desactives' => $comptes->where('statut_compte', STATUT_COMPTE_DESACTIVE)->count(),
                'nouveaux_7j' => $comptes->where('created_at', '>=', now()->subDays(7))->count(),
                'nouveaux_30j' => $comptes->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'inscriptions_par_semaine' => $inscriptionsParSemaine,
            'commandes' => [
                'total' => $commandesParStatut->sum(),
                'validees' => (int) ($commandesParStatut[STATUT_COMMANDE_VALIDEE] ?? 0),
                'livrees' => (int) ($commandesParStatut[STATUT_COMMANDE_LIVREE] ?? 0),
                'annulees' => (int) ($commandesParStatut[STATUT_COMMANDE_ANNULEE] ?? 0),
            ],
            'commissions' => [
                'total_credite' => (float) TransactionPortefeuilleCommercial::whereIn('commercial_id', $idsHumains)
                    ->where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)->sum('montant'),
                'total_repris' => (float) TransactionPortefeuilleCommercial::whereIn('commercial_id', $idsHumains)
                    ->where('type', TYPE_TRANSACTION_PORTEFEUILLE_DEBIT)->where('libelle', 'Commande annulée')->sum('montant'),
                'total_retire' => (float) TransactionPortefeuilleCommercial::whereIn('commercial_id', $idsHumains)
                    ->where('type', TYPE_TRANSACTION_PORTEFEUILLE_DEBIT)->where('libelle', 'Retrait effectué')->sum('montant'),
                'solde_total_portefeuilles' => (float) Commercial::where('type_commercial', TYPE_COMMERCIAL_HUMAIN)->sum('solde_portefeuille'),
            ],
            'retraits' => ['par_statut' => $retraitsParStatut],
            'top_commerciaux' => $topCommerciaux,
        ]);
    }
}
