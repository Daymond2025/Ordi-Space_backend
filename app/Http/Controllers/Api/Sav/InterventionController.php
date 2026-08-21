<?php

namespace App\Http\Controllers\Api\Sav;

use App\Http\Controllers\Controller;
use App\Models\Intervention;
use App\Models\RendezVous;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InterventionController extends Controller
{
    public function store(Request $request, RendezVous $rendezVous): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_SAV_TRAITER), 403);

        $data = $request->validate([
            'technicien_id' => ['nullable', 'exists:techniciens_maintenance,user_id'],
            'diagnostic' => ['nullable', 'string'],
            'reparation_effectuee' => ['nullable', 'string'],
            'statut_intervention' => ['required', Rule::in([
                STATUT_INTERVENTION_PLANIFIEE, STATUT_INTERVENTION_EN_COURS,
                STATUT_INTERVENTION_TERMINEE, STATUT_INTERVENTION_ANNULEE,
            ])],
            'cout' => ['nullable', 'numeric', 'min:0'],
        ]);

        // technicien_id n'est pas forcément l'utilisateur courant : un admin
        // peut saisir l'intervention pour le compte du technicien déjà
        // affecté au rendez-vous (ou en préciser un explicitement).
        $technicienId = $data['technicien_id']
            ?? $rendezVous->technicien_id
            ?? ($request->user()->type_utilisateur === ROLE_TECHNICIEN_MAINTENANCE ? $request->user()->id : null);

        if (! $technicienId) {
            throw ValidationException::withMessages([
                'technicien_id' => ['Aucun technicien affecté à ce rendez-vous : précisez-en un.'],
            ]);
        }

        $intervention = Intervention::updateOrCreate(
            ['rendez_vous_id' => $rendezVous->id],
            [
                ...collect($data)->except('technicien_id')->all(),
                'technicien_id' => $technicienId,
                'date_intervention' => now(),
            ]
        );

        if ($data['statut_intervention'] === STATUT_INTERVENTION_TERMINEE) {
            $rendezVous->demandeSav()->update(['statut_demande' => STATUT_DEMANDE_SAV_RESOLUE]);
        }

        return $this->success($intervention);
    }
}
