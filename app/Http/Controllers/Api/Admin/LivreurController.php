<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Livraison;
use App\Models\Livreur;
use App\Models\Paiement;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class LivreurController extends Controller
{
    /**
     * KPIs agrégés pour le "Tableau de bord" de l'espace Livreurs — même
     * esprit qu'Admin\ClientController::tableauDeBord() (comptes, inscriptions
     * par semaine, répartitions), complété par les indicateurs propres au
     * métier de livreur : pipeline des missions (vivier → livrée) et retard de
     * reversement du cash COD, invisibles autrement sans ouvrir chaque fiche.
     */
    public function tableauDeBord(): JsonResponse
    {
        $rechercheEnLigne = now()->subMinutes(15);
        $comptes = $this->baseLivreursQuery()->get(['id', 'created_at', 'statut_compte', 'derniere_connexion']);

        $profils = Livreur::all(['user_id', 'disponible', 'type_vehicule']);

        $typesVehicule = ['moto' => 0, 'voiture' => 0, 'tricycle' => 0, 'velo' => 0, 'non_defini' => 0];
        foreach ($profils as $profil) {
            $type = in_array($profil->type_vehicule, array_keys($typesVehicule), true) ? $profil->type_vehicule : 'non_defini';
            $typesVehicule[$type]++;
        }

        $inscriptionsParSemaine = [];
        for ($i = 7; $i >= 0; $i--) {
            $debut = now()->subWeeks($i)->startOfWeek();
            $fin = now()->subWeeks($i)->endOfWeek();
            $inscriptionsParSemaine[] = [
                'semaine' => $debut->format('d/m'),
                'total' => $comptes->whereBetween('created_at', [$debut, $fin])->count(),
            ];
        }

        // Le vivier ("en_attente_livreur") est inclus : c'est le volume de
        // livraisons publiées sans preneur, un signal opérationnel direct
        // pour l'admin. "en_preparation" (pas encore publiée par le
        // coordinateur) est exclu, comme sur le tableau de bord du livreur
        // lui-même (MoiController::paiements() → missions_recues).
        $missionsParStatut = Livraison::whereIn('statut_livraison', [
            STATUT_LIVRAISON_EN_ATTENTE_LIVREUR, STATUT_LIVRAISON_ASSIGNEE,
            STATUT_LIVRAISON_EN_COURS, STATUT_LIVRAISON_LIVREE, STATUT_LIVRAISON_ECHOUEE,
        ])->selectRaw('statut_livraison, count(*) as total')->groupBy('statut_livraison')->pluck('total', 'statut_livraison');

        $retoursParStatut = Livraison::where('retour_necessaire', true)
            ->selectRaw('statut_retour, count(*) as total')->groupBy('statut_retour')->pluck('total', 'statut_retour');

        $codEnAttenteDepot = Paiement::where('mode_paiement', MODE_PAIEMENT_ESPECES)
            ->where('statut_paiement', STATUT_PAIEMENT_CONFIRME)
            ->whereNull('date_depot');

        return $this->success([
            'livreurs' => [
                'total' => $comptes->count(),
                'actifs' => $comptes->where('statut_compte', STATUT_COMPTE_ACTIF)->count(),
                'suspendus' => $comptes->where('statut_compte', STATUT_COMPTE_SUSPENDU)->count(),
                'desactives' => $comptes->where('statut_compte', STATUT_COMPTE_DESACTIVE)->count(),
                'en_ligne' => $comptes->filter(fn ($c) => $c->derniere_connexion && $c->derniere_connexion->greaterThanOrEqualTo($rechercheEnLigne))->count(),
                'disponibles_maintenant' => $profils->where('disponible', true)->count(),
                'nouveaux_7j' => $comptes->where('created_at', '>=', now()->subDays(7))->count(),
                'nouveaux_30j' => $comptes->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'types_vehicule' => $typesVehicule,
            'inscriptions_par_semaine' => $inscriptionsParSemaine,
            'missions' => [
                'vivier' => (int) ($missionsParStatut[STATUT_LIVRAISON_EN_ATTENTE_LIVREUR] ?? 0),
                'en_cours' => (int) ($missionsParStatut[STATUT_LIVRAISON_EN_COURS] ?? 0),
                'livrees' => (int) ($missionsParStatut[STATUT_LIVRAISON_LIVREE] ?? 0),
                'par_statut' => $missionsParStatut,
            ],
            'retours' => [
                'total' => $retoursParStatut->sum(),
                'par_statut' => $retoursParStatut,
            ],
            'paiements_cod' => [
                'total_encaisse' => (float) Paiement::where('mode_paiement', MODE_PAIEMENT_ESPECES)
                    ->where('statut_paiement', STATUT_PAIEMENT_CONFIRME)->sum('montant'),
                'non_depose' => (float) (clone $codEnAttenteDepot)->sum('montant'),
                'en_retard' => (clone $codEnAttenteDepot)->where('date_limite_depot', '<', now())->count(),
            ],
        ]);
    }

    private function baseLivreursQuery()
    {
        return User::query()->where('type_utilisateur', ROLE_LIVREUR);
    }
}
