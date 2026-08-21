<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalAudit;
use App\Models\ProgressionTutoriel;
use App\Models\Tutoriel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TutorielController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Tutoriel::query();

        if ($request->user()->type_utilisateur !== ROLE_ADMINISTRATEUR) {
            $query->where('statut', STATUT_TUTORIEL_PUBLIE);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return $this->success($query->latest('date_publication')->paginate(paginate_per_page($request)));
    }

    public function show(Request $request, Tutoriel $tutoriel): JsonResponse
    {
        if ($tutoriel->statut !== STATUT_TUTORIEL_PUBLIE && $request->user()->type_utilisateur !== ROLE_ADMINISTRATEUR) {
            abort(404);
        }

        return $this->success($tutoriel);
    }

    public function store(Request $request): JsonResponse
    {
        $data = collect($this->validerDonnees($request))->except('image')->all();

        $tutoriel = Tutoriel::create([
            ...$data,
            'type' => $data['type'] ?? TYPE_TUTORIEL_RAPIDE,
            'statut' => $data['statut'] ?? STATUT_TUTORIEL_BROUILLON,
            'image_couverture' => $this->stockerImage($request),
            'date_publication' => ($data['statut'] ?? STATUT_TUTORIEL_BROUILLON) === STATUT_TUTORIEL_PUBLIE ? now() : null,
        ]);

        return $this->success($tutoriel, status: 201);
    }

    public function update(Request $request, Tutoriel $tutoriel): JsonResponse
    {
        $data = collect($this->validerDonnees($request, $tutoriel))->except('image')->all();

        $nouvelleImage = $this->stockerImage($request);
        if ($nouvelleImage) {
            if ($tutoriel->cheminStockage()) {
                Storage::disk(IMAGE_PRODUIT_DISQUE)->delete($tutoriel->cheminStockage());
            }
            $data['image_couverture'] = $nouvelleImage;
        }

        if (($data['statut'] ?? $tutoriel->statut) === STATUT_TUTORIEL_PUBLIE && $tutoriel->statut !== STATUT_TUTORIEL_PUBLIE) {
            $data['date_publication'] = now();
        }

        $tutoriel->update($data);

        return $this->success($tutoriel->fresh());
    }

    /**
     * Suivi individuel Academy Space : appelé par l'app mobile à l'ouverture
     * d'un tuto/formation — première fois pour ce client = "vu", les
     * ouvertures suivantes ne créent pas de doublon (contrainte unique).
     */
    public function marquerVu(Request $request, Tutoriel $tutoriel): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_CLIENT, 403);

        $progression = ProgressionTutoriel::firstOrCreate(
            ['client_id' => $request->user()->id, 'tutoriel_id' => $tutoriel->id],
            ['statut' => STATUT_PROGRESSION_VU, 'date_vue' => now()]
        );

        if ($progression->wasRecentlyCreated) {
            JournalAudit::enregistrer(
                $request->user()->id,
                ACTION_TUTORIEL_VU,
                'tutoriel',
                "A consulté « {$tutoriel->titre} »."
            );
        }

        return $this->success($progression);
    }

    public function destroy(Tutoriel $tutoriel): JsonResponse
    {
        if ($tutoriel->cheminStockage()) {
            Storage::disk(IMAGE_PRODUIT_DISQUE)->delete($tutoriel->cheminStockage());
        }
        $tutoriel->delete();

        return $this->success(['message' => 'Tutoriel supprimé.']);
    }

    private function validerDonnees(Request $request, ?Tutoriel $tutoriel = null): array
    {
        $requis = $tutoriel ? 'sometimes' : 'required';

        return $request->validate([
            'titre' => [$requis, 'string', 'max:200'],
            'type' => ['nullable', Rule::in([TYPE_TUTORIEL_RAPIDE, TYPE_TUTORIEL_FORMATION])],
            'url_video' => ['nullable', 'url', 'regex:/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\//'],
            'contenu' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in([STATUT_TUTORIEL_BROUILLON, STATUT_TUTORIEL_PUBLIE])],
            'image' => ['nullable', 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
        ]);
    }

    private function stockerImage(Request $request): ?string
    {
        return $request->hasFile('image')
            ? $request->file('image')->store(TUTORIEL_DOSSIER, IMAGE_PRODUIT_DISQUE)
            : null;
    }
}
