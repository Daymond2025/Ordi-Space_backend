<?php

namespace App\Http\Controllers\Api\Garantix;

use App\Http\Controllers\Controller;
use App\Models\FormuleGarantix;
use App\Models\PrestationGarantix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FormuleController extends Controller
{
    /**
     * Catalogue public des formules GarantiX (visible par tout utilisateur
     * authentifié) — les formules retirées de la vente restent visibles à
     * l'Administrateur pour historique, masquées pour les autres.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FormuleGarantix::with('prestations');

        if ($request->user()->type_utilisateur !== ROLE_ADMINISTRATEUR) {
            $query->where('actif', true);
        }

        return $this->success($query->orderBy('ordre_affichage')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'libelle_complet' => ['required', 'string', 'max:150'],
            'libelle_badge' => ['nullable', 'string', 'max:50'],
            'prix_annuel' => ['required', 'numeric', 'min:0'],
            'frequence_interventions' => ['required', 'integer', 'min:1', 'max:255'],
            'description' => ['nullable', 'string'],
            'ordre_affichage' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->success(FormuleGarantix::create($data), status: 201);
    }

    public function update(Request $request, FormuleGarantix $formule): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['sometimes', 'string', 'max:100'],
            'libelle_complet' => ['sometimes', 'string', 'max:150'],
            'libelle_badge' => ['nullable', 'string', 'max:50'],
            'prix_annuel' => ['sometimes', 'numeric', 'min:0'],
            'frequence_interventions' => ['sometimes', 'integer', 'min:1', 'max:255'],
            'description' => ['nullable', 'string'],
            'ordre_affichage' => ['nullable', 'integer', 'min:0'],
            'actif' => ['sometimes', 'boolean'],
        ]);

        $formule->update($data);

        return $this->success($formule->fresh('prestations'));
    }

    /**
     * Suppression définitive — refusée si des abonnements existent déjà sur
     * cette formule (utiliser "actif" => false pour la retirer de la vente
     * sans casser l'historique des clients déjà abonnés).
     */
    public function destroy(FormuleGarantix $formule): JsonResponse
    {
        if ($formule->abonnements()->exists()) {
            throw ValidationException::withMessages([
                'formule' => ['Cette formule a déjà des abonnements et ne peut pas être supprimée. Désactivez-la (actif: false) à la place.'],
            ]);
        }

        $formule->delete();

        return $this->success(['message' => 'Formule supprimée.']);
    }

    public function ajouterPrestation(Request $request, FormuleGarantix $formule): JsonResponse
    {
        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'ordre_affichage' => ['nullable', 'integer', 'min:0'],
        ]);

        $prestation = $formule->prestations()->create($data);

        return $this->success($prestation, status: 201);
    }

    public function supprimerPrestation(PrestationGarantix $prestation): JsonResponse
    {
        $prestation->delete();

        return $this->success(['message' => 'Prestation supprimée.']);
    }
}
