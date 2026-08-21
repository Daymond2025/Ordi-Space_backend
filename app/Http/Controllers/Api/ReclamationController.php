<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\JournalAudit;
use App\Models\Reclamation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReclamationController extends Controller
{
    /**
     * Distinct des déclarations de panne SAV : une réclamation couvre un
     * litige général (commande, livraison, facturation…), pas une panne
     * matérielle à diagnostiquer par un technicien.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Reclamation::with(['client.user', 'commande']);

        if ($user->type_utilisateur === ROLE_CLIENT) {
            $query->where('client_id', $user->id);
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        return $this->success($query->latest('date_reclamation')->paginate(paginate_per_page($request)));
    }

    public function show(Request $request, Reclamation $reclamation): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->type_utilisateur !== ROLE_CLIENT || $reclamation->client_id === $user->id, 403);

        return $this->success($reclamation->load(['client.user', 'commande']));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_CLIENT, 403);

        $data = $request->validate([
            'commande_id' => ['nullable', 'exists:commandes,id'],
            'sujet' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        if (! empty($data['commande_id'])) {
            $commande = Commande::findOrFail($data['commande_id']);
            abort_unless($commande->client_id === $request->user()->id, 403, 'Cette commande ne vous appartient pas.');
        }

        $reclamation = Reclamation::create([
            ...$data,
            'client_id' => $request->user()->id,
            'statut' => STATUT_RECLAMATION_NOUVELLE,
            'date_reclamation' => now(),
        ]);

        JournalAudit::enregistrer(
            $request->user()->id,
            ACTION_RECLAMATION_CREEE,
            'reclamation',
            "A déposé une réclamation : « {$data['sujet']} »"
        );

        return $this->success($reclamation, status: 201);
    }

    public function repondre(Request $request, Reclamation $reclamation): JsonResponse
    {
        $data = $request->validate([
            'statut' => ['required', Rule::in([
                STATUT_RECLAMATION_EN_COURS, STATUT_RECLAMATION_RESOLUE, STATUT_RECLAMATION_REJETEE,
            ])],
            'reponse_admin' => ['required', 'string', 'max:2000'],
        ]);

        $reclamation->update([
            ...$data,
            'date_traitement' => now(),
        ]);

        return $this->success($reclamation->fresh(['client.user', 'commande']));
    }
}
