<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReponseRapide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bibliothèque "Réponse rapide" (app Commercial) — contenu entièrement géré
 * par l'Admin (store/update/destroy, permission dédiée), lu par le
 * Commercial (et l'Admin) via index(), copié via marquerCopiee() (compteur
 * "Plus utilisés"). Voir EcranReponseRapide.tsx côté frontend.
 */
class ReponseRapideController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ReponseRapide::query();

        if ($request->string('filtre')->toString() === 'favoris') {
            $query->where('est_favori', true);
        }

        if ($request->string('filtre')->toString() === 'plus_utilises') {
            $query->orderByDesc('nombre_copies')->orderByDesc('created_at');
        } elseif ($request->string('tri')->toString() === 'alphabetique') {
            $query->orderBy('titre');
        } else {
            $query->latest();
        }

        return $this->success($query->get());
    }

    /** Écran d'édition (Admin_Web). */
    public function show(ReponseRapide $reponseRapide): JsonResponse
    {
        return $this->success($reponseRapide);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validerDonnees($request);

        return $this->success(ReponseRapide::create($data), status: 201);
    }

    public function update(Request $request, ReponseRapide $reponseRapide): JsonResponse
    {
        $data = $this->validerDonnees($request);

        $reponseRapide->update($data);

        return $this->success($reponseRapide->fresh());
    }

    public function destroy(ReponseRapide $reponseRapide): JsonResponse
    {
        $reponseRapide->delete();

        return $this->success(['message' => 'Réponse rapide supprimée.']);
    }

    /** Appelé quand le commercial tape "Copier" — alimente l'onglet "Plus utilisés". */
    public function marquerCopiee(ReponseRapide $reponseRapide): JsonResponse
    {
        $reponseRapide->increment('nombre_copies');

        return $this->success(['nombre_copies' => $reponseRapide->nombre_copies]);
    }

    private function validerDonnees(Request $request): array
    {
        return $request->validate([
            'titre' => ['required', 'string', 'max:150'],
            'contenu' => ['required', 'string'],
            'est_favori' => ['sometimes', 'boolean'],
        ]);
    }
}
