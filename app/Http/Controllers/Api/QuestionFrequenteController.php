<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionFrequente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class QuestionFrequenteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = QuestionFrequente::query();

        if ($request->user()->type_utilisateur !== ROLE_ADMINISTRATEUR) {
            $query->where('statut', STATUT_QUESTION_PUBLIE);
        }

        return $this->success($query->orderBy('ordre_affichage')->orderBy('id')->paginate(paginate_per_page($request)));
    }

    public function show(Request $request, QuestionFrequente $questionFrequente): JsonResponse
    {
        if ($questionFrequente->statut !== STATUT_QUESTION_PUBLIE && $request->user()->type_utilisateur !== ROLE_ADMINISTRATEUR) {
            abort(404);
        }

        return $this->success($questionFrequente);
    }

    public function store(Request $request): JsonResponse
    {
        $data = collect($this->validerDonnees($request))->except('audio')->all();

        $question = QuestionFrequente::create([
            ...$data,
            'statut' => $data['statut'] ?? STATUT_QUESTION_BROUILLON,
            'fichier_audio' => $this->stockerAudio($request),
        ]);

        return $this->success($question, status: 201);
    }

    public function update(Request $request, QuestionFrequente $questionFrequente): JsonResponse
    {
        $data = collect($this->validerDonnees($request, $questionFrequente))->except('audio')->all();

        $nouvelAudio = $this->stockerAudio($request);
        if ($nouvelAudio) {
            if ($questionFrequente->cheminStockageAudio()) {
                Storage::disk(IMAGE_PRODUIT_DISQUE)->delete($questionFrequente->cheminStockageAudio());
            }
            $data['fichier_audio'] = $nouvelAudio;
        }

        $questionFrequente->update($data);

        return $this->success($questionFrequente->fresh());
    }

    public function destroy(QuestionFrequente $questionFrequente): JsonResponse
    {
        if ($questionFrequente->cheminStockageAudio()) {
            Storage::disk(IMAGE_PRODUIT_DISQUE)->delete($questionFrequente->cheminStockageAudio());
        }
        $questionFrequente->delete();

        return $this->success(['message' => 'Question supprimée.']);
    }

    private function validerDonnees(Request $request, ?QuestionFrequente $questionFrequente = null): array
    {
        $requis = $questionFrequente ? 'sometimes' : 'required';

        return $request->validate([
            'question' => [$requis, 'string', 'max:500'],
            'reponse' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in([STATUT_QUESTION_BROUILLON, STATUT_QUESTION_PUBLIE])],
            'ordre_affichage' => ['nullable', 'integer', 'min:0'],
            'audio' => ['nullable', 'file', 'mimes:'.AUDIO_MIMES_AUTORISES, 'max:'.AUDIO_MAX_POIDS_KO],
        ]);
    }

    private function stockerAudio(Request $request): ?string
    {
        return $request->hasFile('audio')
            ? $request->file('audio')->store(ASSISTANCE_AUDIO_DOSSIER, IMAGE_PRODUIT_DISQUE)
            : null;
    }
}
