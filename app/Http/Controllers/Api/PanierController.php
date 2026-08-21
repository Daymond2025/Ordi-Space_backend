<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalAudit;
use App\Models\LignePanier;
use App\Models\Panier;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $panier = $this->panierDuClient($request);

        return $this->success($panier->load('lignes.produit.images'));
    }

    public function ajouter(Request $request): JsonResponse
    {
        $data = $request->validate([
            'produit_id' => ['required', 'exists:produits,id'],
            'quantite' => ['nullable', 'integer', 'min:1'],
        ]);

        $panier = $this->panierDuClient($request);
        $quantite = $data['quantite'] ?? 1;

        $ligne = $panier->lignes()->where('produit_id', $data['produit_id'])->first();

        if ($ligne) {
            $ligne->update(['quantite' => $ligne->quantite + $quantite]);
        } else {
            $ligne = $panier->lignes()->create(['produit_id' => $data['produit_id'], 'quantite' => $quantite]);
        }

        $produit = Produit::find($data['produit_id']);
        JournalAudit::enregistrer(
            $request->user()->id,
            ACTION_PANIER_AJOUT,
            'produit',
            "A ajouté « {$produit?->nom_produit} » au panier."
        );

        return $this->success($panier->load('lignes.produit.images'), status: 201);
    }

    public function modifier(Request $request, LignePanier $ligne): JsonResponse
    {
        abort_unless($ligne->panier->client_id === $request->user()->id, 403);

        $data = $request->validate(['quantite' => ['required', 'integer', 'min:1']]);
        $ligne->update($data);

        return $this->success($ligne->panier->load('lignes.produit.images'));
    }

    public function supprimer(Request $request, LignePanier $ligne): JsonResponse
    {
        abort_unless($ligne->panier->client_id === $request->user()->id, 403);

        $panier = $ligne->panier;
        $ligne->delete();

        return $this->success($panier->load('lignes.produit.images'));
    }

    public function vider(Request $request): JsonResponse
    {
        $panier = $this->panierDuClient($request);
        $panier->lignes()->delete();

        return $this->success(['message' => 'Panier vidé.']);
    }

    private function panierDuClient(Request $request): Panier
    {
        return Panier::firstOrCreate(['client_id' => $request->user()->id]);
    }
}
