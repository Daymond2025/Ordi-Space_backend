<?php

namespace App\Http\Controllers\Api\Garantix;

use App\Http\Controllers\Controller;
use App\Models\ExclusionGarantix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExclusionController extends Controller
{
    /**
     * Liste unique d'exclusions, commune à toutes les formules GarantiX
     * (cf. maquette : un seul bloc "Exclusions" partagé).
     */
    public function index(): JsonResponse
    {
        return $this->success(ExclusionGarantix::orderBy('ordre_affichage')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'ordre_affichage' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->success(ExclusionGarantix::create($data), status: 201);
    }

    public function destroy(ExclusionGarantix $exclusion): JsonResponse
    {
        $exclusion->delete();

        return $this->success(['message' => 'Exclusion supprimée.']);
    }
}
