<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coordinateur;
use App\Models\JournalAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Espace Coordinateur côté Admin (superviseur global) — liste, fiche et fil
 * d'activité d'un coordinateur précis, pour que l'Admin puisse suivre ce
 * qu'il a réellement fait sur la plateforme. Même pattern que
 * MoiController::activites() (qui ne montre qu'au coordinateur ses propres
 * actions), mais ici pour n'importe quel coordinateur, vu par l'Admin.
 */
class CoordinateurController extends Controller
{
    /** Écran "Coordinateurs" (liste) — CRUD complet côté admin, comme pour un client. */
    public function index(Request $request): JsonResponse
    {
        $terme = trim($request->string('recherche')->toString());

        $coordinateurs = Coordinateur::with('user')
            ->when($terme !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('nom', 'like', "%{$terme}%")
                ->orWhere('prenom', 'like', "%{$terme}%")
                ->orWhere('email', 'like', "%{$terme}%")))
            ->latest('user_id')
            ->paginate(paginate_per_page($request))
            ->through(fn (Coordinateur $c) => [
                'user_id' => $c->user_id,
                'nom' => $c->user->nom,
                'prenom' => $c->user->prenom,
                'email' => $c->user->email,
                'telephone' => $c->user->telephone,
                'statut_compte' => $c->user->statut_compte,
                'zone_couverte' => $c->zone_couverte,
                'membre_depuis' => $c->user->created_at,
            ]);

        return $this->success($coordinateurs);
    }

    public function show(Coordinateur $coordinateur): JsonResponse
    {
        $coordinateur->load('user');

        return $this->success([
            'user_id' => $coordinateur->user_id,
            'nom' => $coordinateur->user->nom,
            'prenom' => $coordinateur->user->prenom,
            'email' => $coordinateur->user->email,
            'telephone' => $coordinateur->user->telephone,
            'statut_compte' => $coordinateur->user->statut_compte,
            'adresse' => $coordinateur->adresse,
            'horaires' => $coordinateur->horaires,
            'zone_couverte' => $coordinateur->zone_couverte,
            'membre_depuis' => $coordinateur->user->created_at,
            'statistiques' => [
                'commandes_validees' => $coordinateur->commandesValidees()->count(),
                'produits_valides' => $coordinateur->validationsProduits()->count(),
                'activites' => JournalAudit::where('acteur_id', $coordinateur->user_id)->count(),
            ],
        ]);
    }

    /** Fiche pratique (adresse/horaires/zone_couverte) — "Ton coordinateur" côté Livreur (Coordinateur::fichePourLivreur()). */
    public function modifierProfil(Request $request, Coordinateur $coordinateur): JsonResponse
    {
        $data = $request->validate([
            'adresse' => ['nullable', 'string', 'max:255'],
            'horaires' => ['nullable', 'string', 'max:255'],
            'zone_couverte' => ['nullable', 'string', 'max:255'],
        ]);

        $coordinateur->update($data);

        return $this->success($coordinateur->fresh());
    }

    public function activites(Request $request, Coordinateur $coordinateur): JsonResponse
    {
        [$debut, $fin] = resoudre_periode($request);

        $activites = JournalAudit::where('acteur_id', $coordinateur->user_id)
            ->when($debut && $fin, fn ($q) => $q->whereBetween('date_heure', [$debut, $fin]))
            ->latest('date_heure')
            ->paginate(paginate_per_page($request));

        return $this->success($activites);
    }
}
