<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categorie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class CategorieController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->success(Categorie::orderBy('nom_categorie')->get());
    }

    /**
     * Choix de l'écran "Catégorie" de la Boutique (public, comme le catalogue) :
     * types d'ordinateur, tuiles d'accessoires, logiciels par groupe. Seules les
     * catégories rangées dans une famille avec un `ordre_filtre` y figurent.
     */
    public function filtres(): JsonResponse
    {
        $choix = Categorie::whereNotNull('famille')->whereNotNull('ordre_filtre')
            ->orderBy('ordre_filtre')->get()
            ->map(fn (Categorie $c) => [
                'id' => $c->id,
                'nom' => $c->libelle ?: $c->nom_categorie,
                'famille' => $c->famille,
                'groupe' => $c->groupe,
            ]);
        $de = fn (string $famille) => $choix->where('famille', $famille)->values();

        return $this->success([
            'ordinateur' => ['types' => $de('ordinateur')->map(fn ($c) => Arr::only($c, ['id', 'nom']))->all()],
            'accessoires' => ['categories' => $de('accessoires')->map(fn ($c) => Arr::only($c, ['id', 'nom']))->all()],
            'logiciels' => [
                'groupes' => $de('logiciels')->groupBy('groupe')->map(fn ($liste, $titre) => [
                    'titre' => $titre,
                    'categories' => $liste->map(fn ($c) => Arr::only($c, ['id', 'nom']))->values()->all(),
                ])->values()->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // Gestion du référentiel de catégories réservée à l'Administrateur.
        abort_unless($request->user()->hasRole(ROLE_ADMINISTRATEUR), 403);

        $data = $request->validate($this->regles());

        return $this->success(Categorie::create($data), status: 201);
    }

    /**
     * Range une catégorie dans l'écran "Catégorie" de la Boutique (famille, groupe,
     * libellé de la tuile, ordre) ou la renomme — Administrateur uniquement.
     */
    public function update(Request $request, Categorie $categorie): JsonResponse
    {
        abort_unless($request->user()->hasRole(ROLE_ADMINISTRATEUR), 403);

        $categorie->update($request->validate($this->regles($categorie)));

        return $this->success($categorie->fresh());
    }

    /**
     * `famille` + `ordre_filtre` font apparaître la catégorie comme choix des filtres
     * (voir filtres()) ; `groupe` ne sert qu'aux logiciels ("Pack office", "Navigateur"…),
     * `libelle` est le nom court affiché sur la tuile (à défaut, le nom de la catégorie).
     */
    private function regles(?Categorie $categorie = null): array
    {
        return [
            'nom_categorie' => [$categorie ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('categories', 'nom_categorie')->ignore($categorie?->id)],
            'description' => ['nullable', 'string'],
            'famille' => ['nullable', Rule::in(FAMILLES_CATEGORIE)],
            'groupe' => ['nullable', 'string', 'max:100'],
            'libelle' => ['nullable', 'string', 'max:100'],
            'ordre_filtre' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
