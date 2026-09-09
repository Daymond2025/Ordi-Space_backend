<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Paiement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaiementController extends Controller
{
    /**
     * Deux cas : le livreur encaisse à la remise du colis (commande
     * physique), ou le client règle lui-même une commande 100% numérique
     * (logiciels) qui n'a jamais de livreur assigné.
     */
    public function encaisser(Request $request, Commande $commande): JsonResponse
    {
        $user = $request->user();
        $livreurAutorise = $commande->livraison && $commande->livraison->livreur_id === $user->id;
        $clientAutorise = ! $commande->livraison && $commande->client_id === $user->id;

        abort_unless($livreurAutorise || $clientAutorise, 403);

        if ($commande->paiement) {
            throw ValidationException::withMessages(['commande' => ['Cette commande est déjà réglée.']]);
        }

        $data = $request->validate([
            'mode_paiement' => ['required', Rule::in([MODE_PAIEMENT_MOBILE_MONEY, MODE_PAIEMENT_ESPECES])],
            'reference_transaction' => ['nullable', 'string', 'max:255'],
        ]);

        // Délai de reversement du cash à l'entreprise — uniquement quand un
        // livreur encaisse physiquement des espèces (mobile money ne transite
        // jamais par ses mains). Voir écran "Les Missions" (badge "Dépôt").
        $especesEncaisseesParLivreur = $livreurAutorise && $data['mode_paiement'] === MODE_PAIEMENT_ESPECES;

        $paiement = Paiement::create([
            ...$data,
            'commande_id' => $commande->id,
            'livreur_id' => $livreurAutorise ? $user->id : null,
            // Montant net : total des lignes moins la remise Privilège Space
            // éventuellement appliquée à la commande (cf. montantNet()).
            'montant' => $commande->montantNet(),
            'statut_paiement' => STATUT_PAIEMENT_CONFIRME,
            'date_paiement' => now(),
            'date_limite_depot' => $especesEncaisseesParLivreur ? now()->addHours(24) : null,
        ]);

        return $this->success($paiement, status: 201);
    }

    public function show(Request $request, Commande $commande): JsonResponse
    {
        abort_unless($commande->paiement, 404);

        return $this->success($commande->paiement);
    }

    /** Le livreur confirme avoir reversé à l'entreprise le cash COD encaissé. */
    public function deposer(Request $request, Paiement $paiement): JsonResponse
    {
        abort_unless($paiement->livreur_id === $request->user()->id, 403);

        $paiement->update(['date_depot' => now()]);

        return $this->success($paiement->fresh());
    }
}
