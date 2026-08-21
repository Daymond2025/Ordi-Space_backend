<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Privilege;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrivilegeController extends Controller
{
    /**
     * Privilège Space : catalogue visible par tous les clients authentifiés
     * (pas de déblocage progressif — décision d'architecture validée).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Privilege::query();

        if ($request->user()->type_utilisateur !== ROLE_ADMINISTRATEUR) {
            $query->where('actif', true)
                ->where(fn ($q) => $q->whereNull('date_debut')->orWhereDate('date_debut', '<=', now()))
                ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', now()));
        }

        return $this->success($query->orderBy('ordre_affichage')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validerDonnees($request);

        return $this->success(Privilege::create($data), status: 201);
    }

    public function update(Request $request, Privilege $privilege): JsonResponse
    {
        $data = $this->validerDonnees($request, $privilege);

        $privilege->update($data);

        return $this->success($privilege->fresh());
    }

    public function destroy(Privilege $privilege): JsonResponse
    {
        $privilege->delete();

        return $this->success(['message' => 'Privilège supprimé.']);
    }

    private function validerDonnees(Request $request, ?Privilege $privilege = null): array
    {
        $requis = $privilege ? 'sometimes' : 'required';
        $type = $request->input('type_privilege', $privilege?->type_privilege);

        // Remise % / remise fixe : le client saisit un code au checkout.
        // Livraison gratuite / parrainage : automatiques, pas de code catalogue.
        $codeRequis = in_array($type, [TYPE_PRIVILEGE_REMISE_POURCENTAGE, TYPE_PRIVILEGE_REMISE_MONTANT], true);
        // Remise % / remise fixe / parrainage ont besoin d'un montant chiffré.
        $valeurRequise = in_array($type, [
            TYPE_PRIVILEGE_REMISE_POURCENTAGE, TYPE_PRIVILEGE_REMISE_MONTANT, TYPE_PRIVILEGE_PARRAINAGE,
        ], true);

        return $request->validate([
            'titre' => [$requis, 'string', 'max:150'],
            'sous_titre' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'type_privilege' => [$requis, Rule::in([
                TYPE_PRIVILEGE_REMISE_POURCENTAGE, TYPE_PRIVILEGE_REMISE_MONTANT,
                TYPE_PRIVILEGE_LIVRAISON_GRATUITE, TYPE_PRIVILEGE_PARRAINAGE,
            ])],
            'valeur' => [$valeurRequise ? 'required' : 'nullable', 'numeric', 'min:0'],
            'code_promo' => [
                $codeRequis ? 'required' : 'nullable', 'string', 'max:50',
                Rule::unique('privileges', 'code_promo')->ignore($privilege?->id),
            ],
            'limite_utilisation_par_client' => ['nullable', 'integer', 'min:1'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'actif' => ['sometimes', 'boolean'],
            'ordre_affichage' => ['nullable', 'integer', 'min:0'],
            'couleur_debut' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'couleur_fin' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
    }
}
