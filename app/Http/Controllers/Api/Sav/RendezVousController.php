<?php

namespace App\Http\Controllers\Api\Sav;

use App\Http\Controllers\Controller;
use App\Models\DemandeSav;
use App\Models\RendezVous;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RendezVousController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = RendezVous::with(['demandeSav.client.user', 'technicien.user', 'intervention']);

        if ($user->type_utilisateur === ROLE_TECHNICIEN_MAINTENANCE) {
            $query->where(fn ($q) => $q->where('technicien_id', $user->id)->orWhereNull('technicien_id'));
        }

        return $this->success($query->latest('date_rdv')->paginate(paginate_per_page($request)));
    }

    public function show(RendezVous $rendezVous): JsonResponse
    {
        return $this->success($rendezVous->load(['demandeSav.client.user', 'technicien.user', 'intervention']));
    }

    /**
     * Planifié par le service maintenance suite à une demande SAV.
     */
    public function store(Request $request, DemandeSav $demandeSav): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_SAV_TRAITER), 403);

        $data = $request->validate([
            'technicien_id' => ['nullable', 'exists:techniciens_maintenance,user_id'],
            'date_rdv' => ['required', 'date', 'after:now'],
            'lieu' => ['nullable', 'string', 'max:255'],
        ]);

        $rdv = RendezVous::create([...$data, 'demande_sav_id' => $demandeSav->id]);

        $demandeSav->update(['statut_demande' => STATUT_DEMANDE_SAV_PLANIFIEE]);

        return $this->success($rdv, status: 201);
    }

    /**
     * Réaffectation du technicien et/ou changement de date/lieu après création
     * (le formulaire de création ne permet de le fixer qu'une fois).
     */
    public function update(Request $request, RendezVous $rendezVous): JsonResponse
    {
        $data = $request->validate([
            'technicien_id' => ['nullable', 'exists:techniciens_maintenance,user_id'],
            'date_rdv' => ['sometimes', 'date'],
            'lieu' => ['nullable', 'string', 'max:255'],
        ]);

        $rendezVous->update($data);

        return $this->success($rendezVous->fresh(['demandeSav', 'technicien.user', 'intervention']));
    }
}
