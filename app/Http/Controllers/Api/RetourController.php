<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LigneCommande;
use App\Models\Retour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RetourController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Retour::with(['ligneCommande.produit']);

        if ($user->type_utilisateur === ROLE_FOURNISSEUR) {
            $query->where('fournisseur_id', $user->id);
        }

        return $this->success($query->latest('date_retour')->paginate(paginate_per_page($request)));
    }

    /**
     * Ouvert par le client sur une ligne de commande livrée ; traité ensuite
     * par le fournisseur concerné ("Gérer les retours & garanties").
     */
    public function store(Request $request, LigneCommande $ligneCommande): JsonResponse
    {
        $user = $request->user();
        abort_unless($ligneCommande->commande->client_id === $user->id, 403);

        $data = $request->validate(['motif' => ['required', 'string', 'max:1000']]);

        $retour = Retour::create([
            ...$data,
            'ligne_commande_id' => $ligneCommande->id,
            'fournisseur_id' => $ligneCommande->produit->fournisseur_id,
            'statut_retour' => STATUT_RETOUR_EN_ATTENTE,
            'date_retour' => now(),
        ]);

        return $this->success($retour, status: 201);
    }

    public function traiter(Request $request, Retour $retour): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_RETOURS_TRAITER) && $retour->fournisseur_id === $request->user()->id, 403);

        $data = $request->validate([
            'statut_retour' => ['required', Rule::in([STATUT_RETOUR_ACCEPTE, STATUT_RETOUR_REFUSE, STATUT_RETOUR_REMBOURSE])],
        ]);

        $retour->update($data);

        return $this->success($retour->fresh());
    }
}
