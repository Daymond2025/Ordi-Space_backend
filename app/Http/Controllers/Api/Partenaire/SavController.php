<?php

namespace App\Http\Controllers\Api\Partenaire;

use App\Models\DemandeSav;
use App\Models\NotificationOrdispace;
use App\Services\NotificationAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pannes sur des produits vendus par le partenaire : il est le premier
 * contact. S'il ne peut pas réparer, il transfère à Ordi'Space — la
 * demande entre alors dans le circuit SAV habituel (techniciens).
 */
class SavController extends ControleurPartenaire
{
    public function index(Request $request): JsonResponse
    {
        $query = DemandeSav::with(['client.user:id,nom,prenom,telephone', 'ligneCommandePartenaire.produit.images', 'ligneCommandePartenaire.commande:id,reference'])
            ->where('partenaire_id', $this->partenaire($request)->user_id);

        if ($request->filled('statut_demande')) {
            $query->where('statut_demande', $request->string('statut_demande'));
        }

        return $this->success($query->latest('date_demande')->paginate(paginate_per_page($request)));
    }

    public function show(Request $request, DemandeSav $demandeSav): JsonResponse
    {
        $this->autoriser($request, $demandeSav);

        return $this->success($demandeSav->load(['client.user:id,nom,prenom,telephone', 'ligneCommandePartenaire.produit.images', 'ligneCommandePartenaire.commande:id,reference', 'rendezVous.intervention']));
    }

    /** Tant qu'elle n'est pas transférée, le partenaire fait avancer la demande lui-même. */
    public function changerStatut(Request $request, DemandeSav $demandeSav): JsonResponse
    {
        $this->autoriser($request, $demandeSav);
        $this->exigerNonTransferee($demandeSav);

        $data = $request->validate([
            'statut_demande' => ['required', Rule::in([STATUT_DEMANDE_SAV_EN_COURS, STATUT_DEMANDE_SAV_RESOLUE, STATUT_DEMANDE_SAV_CLOTUREE])],
        ]);

        $demandeSav->update($data);

        if ($data['statut_demande'] === STATUT_DEMANDE_SAV_RESOLUE) {
            NotificationOrdispace::create([
                'user_id' => $demandeSav->client_id,
                'type_notification' => 'sav_partenaire_resolue',
                'titre' => 'Panne résolue',
                'contenu' => 'Votre partenaire Ordi\'Space a résolu votre demande de maintenance.',
                'lu' => false,
                'date_envoi' => now(),
            ]);
        }

        return $this->success($demandeSav->fresh());
    }

    public function transferer(Request $request, DemandeSav $demandeSav): JsonResponse
    {
        $this->autoriser($request, $demandeSav);
        $this->exigerNonTransferee($demandeSav);

        if (in_array($demandeSav->statut_demande, [STATUT_DEMANDE_SAV_RESOLUE, STATUT_DEMANDE_SAV_CLOTUREE], true)) {
            throw ValidationException::withMessages(['statut_demande' => ['Cette demande est déjà terminée.']]);
        }

        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        // Repart de zéro côté Ordi'Space : nouvelle demande pour les techniciens.
        $demandeSav->update(['transferee_le' => now(), 'motif_transfert' => $data['motif'], 'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE]);

        NotificationAdminService::notifierTousLesAdmins(
            'panne_declaree',
            'Panne transférée par un partenaire',
            "{$this->partenaire($request)->nom_boutique} transfère une panne : « ".mb_substr($demandeSav->description_probleme, 0, 120).' »'
        );

        return $this->success($demandeSav->fresh());
    }

    private function autoriser(Request $request, DemandeSav $demandeSav): void
    {
        abort_unless($demandeSav->partenaire_id === $this->partenaire($request)->user_id, 404);
    }

    private function exigerNonTransferee(DemandeSav $demandeSav): void
    {
        if ($demandeSav->transferee_le !== null) {
            throw ValidationException::withMessages(['statut_demande' => ["Demande transférée à Ordi'Space : elle est suivie par ses techniciens."]]);
        }
    }
}
