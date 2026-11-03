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
        abort_unless($request->user()->can(PERMISSION_SAV_TRAITER) && $rendezVous->estAccessiblePar($request->user()), 403);

        $data = $request->validate([
            'technicien_id' => ['nullable', 'exists:techniciens_maintenance,user_id'],
            'diagnostic' => ['nullable', 'string'],
            'reparation_effectuee' => ['nullable', 'string'],
            'statut_intervention' => ['required', Rule::in([
                STATUT_INTERVENTION_PLANIFIEE, STATUT_INTERVENTION_EN_COURS,
                STATUT_INTERVENTION_TERMINEE, STATUT_INTERVENTION_ANNULEE,
            ])],
            'cout' => ['nullable', 'numeric', 'min:0'],
            // Écran "Diagnostic technique" (app Maintenancier).
            'problemes' => ['nullable', 'array', 'max:20'],
            'problemes.*' => ['string', 'max:100'],
            'probleme_identifie' => ['nullable', 'string', 'max:2000'],
            'cause_panne' => ['nullable', 'string', 'max:2000'],
            'niveau_gravite' => ['nullable', Rule::in(['faible', 'moyenne', 'critique'])],
            'duree_estimee_minutes' => ['nullable', 'integer', 'min:1', 'max:20160'],
            // Écran "Marquer terminé" (app Maintenancier).
            'prestations' => ['nullable', 'array', 'max:30'],
            'prestations.*' => ['string', 'max:100'],
            'pieces_remplacees' => ['nullable', 'array', 'max:30'],
            'pieces_remplacees.*' => ['string', 'max:100'],
            'date_fin' => ['nullable', 'date'],
            'duree_reelle_minutes' => ['nullable', 'integer', 'min:1', 'max:20160'],
            'note_fin' => ['nullable', 'string', 'max:2000'],
        ]);

        // Résumé texte pour les écrans qui n'affichent que `reparation_effectuee`.
        if (empty($data['reparation_effectuee']) && (! empty($data['prestations']) || ! empty($data['pieces_remplacees']))) {
            $data['reparation_effectuee'] = collect([
                ! empty($data['prestations']) ? implode(', ', $data['prestations']) : null,
                ! empty($data['pieces_remplacees']) ? 'Pièces remplacées : '.implode(', ', $data['pieces_remplacees']) : null,
            ])->filter()->implode(' — ');
        }

        // Résumé texte pour les écrans qui n'affichent que `diagnostic`
        // (Coordinateur) — seulement s'il n'est pas fourni explicitement.
        $probleme = collect([implode(', ', $data['problemes'] ?? []), $data['probleme_identifie'] ?? null])->filter()->implode(' — ');
        if (empty($data['diagnostic']) && $probleme !== '') {
            $data['diagnostic'] = $probleme.(! empty($data['cause_panne']) ? ' — Cause : '.$data['cause_panne'] : '');
        }

        // technicien_id n'est pas forcément l'utilisateur courant : un admin
        // peut saisir l'intervention pour le compte du technicien déjà
        // affecté au rendez-vous (ou en préciser un explicitement).
        // Un technicien n'enregistre l'intervention qu'à son propre nom.
        $technicienId = $request->user()->type_utilisateur === ROLE_TECHNICIEN_MAINTENANCE
            ? $request->user()->id
            : ($data['technicien_id'] ?? $rendezVous->technicien_id);

        // Rendez-vous non affecté pris en charge par un technicien : il lui revient (plus modifiable par un autre).
        if ($rendezVous->technicien_id === null && $request->user()->type_utilisateur === ROLE_TECHNICIEN_MAINTENANCE) {
            $rendezVous->update(['technicien_id' => $technicienId]);
        }

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

        // Même transition que le passage à "terminee" ci-dessous : la demande
        // suit l'intervention, jamais figée à "planifiee" une fois le
        // diagnostic commencé (sans quoi l'écran du technicien resterait
        // bloqué sur "planifier/démarrer" au lieu de passer à "terminer").
        if ($data['statut_intervention'] === STATUT_INTERVENTION_EN_COURS) {
            $rendezVous->demandeSav()->update(['statut_demande' => STATUT_DEMANDE_SAV_EN_COURS]);
        } elseif ($data['statut_intervention'] === STATUT_INTERVENTION_TERMINEE) {
            $rendezVous->demandeSav()->update(['statut_demande' => STATUT_DEMANDE_SAV_RESOLUE]);
            $rendezVous->update(['statut_rdv' => STATUT_RDV_TERMINE]);
        } elseif ($data['statut_intervention'] === STATUT_INTERVENTION_PLANIFIEE) {
            // "Mise en attente" (app Maintenancier) : intervention commencée
            // puis suspendue (pièce à commander…) — la demande redevient planifiée.
            $rendezVous->demandeSav()->where('statut_demande', STATUT_DEMANDE_SAV_EN_COURS)
                ->update(['statut_demande' => STATUT_DEMANDE_SAV_PLANIFIEE]);
        } elseif ($data['statut_intervention'] === STATUT_INTERVENTION_ANNULEE) {
            $rendezVous->demandeSav()->update(['statut_demande' => STATUT_DEMANDE_SAV_CLOTUREE]);
        }

        return $this->success($intervention);
    }
}
