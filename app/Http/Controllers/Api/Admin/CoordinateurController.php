<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coordinateur;
use App\Models\JournalAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Espace Coordinateur côté Admin (superviseur global) — fil d'activité d'un
 * coordinateur précis, pour que l'Admin puisse suivre ce qu'il a réellement
 * fait sur la plateforme. Même pattern que MoiController::activites() (qui
 * ne montre qu'au coordinateur ses propres actions), mais ici pour
 * n'importe quel coordinateur, vu par l'Admin.
 */
class CoordinateurController extends Controller
{
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
