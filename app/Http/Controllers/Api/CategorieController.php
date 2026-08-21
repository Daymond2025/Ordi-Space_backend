<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categorie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategorieController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->success(Categorie::orderBy('nom_categorie')->get());
    }

    public function store(Request $request): JsonResponse
    {
        // Gestion du référentiel de catégories réservée à l'Administrateur.
        abort_unless($request->user()->hasRole(ROLE_ADMINISTRATEUR), 403);

        $data = $request->validate([
            'nom_categorie' => ['required', 'string', 'max:100', 'unique:categories,nom_categorie'],
            'description' => ['nullable', 'string'],
        ]);

        return $this->success(Categorie::create($data), status: 201);
    }
}
