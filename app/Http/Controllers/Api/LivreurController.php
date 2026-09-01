<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liste des livreurs disponibles pour le sélecteur "Envoyer à un livreur"
 * (Espace Coordinateur, écran détail commande) — mêmes permissions que
 * l'assignation elle-même (CommandeController::assignerLivreur()).
 */
class LivreurController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_LIVRAISONS_ASSIGNER), 403);

        $livreurs = User::where('type_utilisateur', ROLE_LIVREUR)
            ->where('statut_compte', STATUT_COMPTE_ACTIF)
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom', 'telephone']);

        return $this->success($livreurs);
    }
}
