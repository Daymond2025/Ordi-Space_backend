<?php

namespace App\Http\Controllers\Api\Sav;

use App\Http\Controllers\Controller;
use App\Models\DemandeSav;
use App\Models\Garantie;
use App\Models\JournalAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DemandeSavController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = DemandeSav::with(['client.user', 'garantie.ligneCommande.produit', 'rendezVous.technicien.user']);

        if ($user->type_utilisateur === ROLE_CLIENT) {
            $query->where('client_id', $user->id);
        }

        if ($request->filled('statut_demande')) {
            $query->where('statut_demande', $request->string('statut_demande'));
        }

        return $this->success($query->latest('date_demande')->paginate(paginate_per_page($request)));
    }

    public function show(Request $request, DemandeSav $demandeSav): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->type_utilisateur !== ROLE_CLIENT || $demandeSav->client_id === $user->id, 403);

        return $this->success($demandeSav->load([
            'client.user',
            'garantie.ligneCommande.produit',
            'rendezVous.technicien.user',
            'rendezVous.intervention',
        ]));
    }

    public function changerStatut(Request $request, DemandeSav $demandeSav): JsonResponse
    {
        $data = $request->validate([
            'statut_demande' => ['required', Rule::in([
                STATUT_DEMANDE_SAV_EN_ATTENTE,
                STATUT_DEMANDE_SAV_PLANIFIEE,
                STATUT_DEMANDE_SAV_EN_COURS,
                STATUT_DEMANDE_SAV_RESOLUE,
                STATUT_DEMANDE_SAV_CLOTUREE,
            ])],
        ]);

        $demandeSav->update($data);

        return $this->success($demandeSav->fresh());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_CLIENT, 403);

        $data = $request->validate([
            'garantie_id' => ['nullable', 'exists:garanties,id'],
            'description_probleme' => ['required', 'string', 'max:2000'],
        ]);

        if (! empty($data['garantie_id'])) {
            $garantie = Garantie::findOrFail($data['garantie_id']);
            abort_unless(
                $garantie->ligneCommande->commande->client_id === $request->user()->id,
                403,
                'Cette garantie ne vous appartient pas.'
            );
        }

        $demande = DemandeSav::create([
            ...$data,
            'client_id' => $request->user()->id,
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
            'date_demande' => now(),
        ]);

        JournalAudit::enregistrer(
            $request->user()->id,
            ACTION_PANNE_DECLAREE,
            'demande_sav',
            "A déclaré une panne : « ".substr($data['description_probleme'], 0, 120)." »"
        );

        return $this->success($demande, status: 201);
    }
}
