<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Garantie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GarantieController extends Controller
{
    /**
     * Garantie de base (gratuite, incluse à l'achat) — distincte des
     * abonnements GarantiX. Le client ne voit que les siennes ; coordinateur
     * et administrateur ont une vue complète pour le support.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Garantie::with(['ligneCommande.produit', 'ligneCommande.commande']);

        if ($user->type_utilisateur === ROLE_CLIENT) {
            $query->whereHas('ligneCommande.commande', fn ($q) => $q->where('client_id', $user->id));
        }

        return $this->success($query->latest('date_fin')->paginate(paginate_per_page($request)));
    }
}
