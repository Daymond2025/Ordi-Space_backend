<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Garantie;
use App\Models\Livraison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LivraisonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Livraison::with(['commande', 'adresse']);

        match ($user->type_utilisateur) {
            // Un livreur voit ses courses + le vivier des livraisons non affectées.
            ROLE_LIVREUR => $query->where(fn ($q) => $q
                ->where('livreur_id', $user->id)
                ->orWhere('statut_livraison', STATUT_LIVRAISON_EN_ATTENTE_LIVREUR)),
            default => null,
        };

        return $this->success($query->latest('id')->paginate(paginate_per_page($request)));
    }

    public function show(Request $request, Livraison $livraison): JsonResponse
    {
        $user = $request->user();
        $autorise = match ($user->type_utilisateur) {
            ROLE_CLIENT => $livraison->commande->client_id === $user->id,
            ROLE_LIVREUR => in_array($livraison->livreur_id, [null, $user->id], true),
            default => true, // coordinateur, administrateur
        };

        abort_unless($autorise, 403);

        return $this->success($livraison->load(['commande.lignes.produit', 'adresse', 'livreur.user']));
    }

    public function affecter(Request $request, Livraison $livraison): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        if ($livraison->livreur_id !== null) {
            throw ValidationException::withMessages(['livraison' => ['Cette livraison est déjà prise en charge.']]);
        }

        $livraison->update([
            'livreur_id' => $request->user()->id,
            'statut_livraison' => STATUT_LIVRAISON_EN_COURS,
            'date_prise_en_charge' => now(),
        ]);

        $livraison->commande()->update(['statut_commande' => STATUT_COMMANDE_EN_LIVRAISON]);

        return $this->success($livraison->fresh());
    }

    public function livrer(Request $request, Livraison $livraison): JsonResponse
    {
        abort_unless($livraison->livreur_id === $request->user()->id, 403);

        $data = $request->validate(['preuve_livraison' => ['required', 'string']]);

        $livraison->update([
            ...$data,
            'statut_livraison' => STATUT_LIVRAISON_LIVREE,
            'date_livraison_effective' => now(),
        ]);

        $livraison->commande()->update(['statut_commande' => STATUT_COMMANDE_LIVREE]);

        Garantie::genererPourCommande($livraison->commande);
        $livraison->commande->crediterParrainageSiEligible();

        return $this->success($livraison->fresh());
    }
}
