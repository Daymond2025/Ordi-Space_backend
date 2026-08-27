<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Fournisseur;
use App\Models\LigneCommande;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de consultation/gestion Fournisseur — Espace Coordinateur,
 * Centre des opérations (produits, commandes, portefeuille financier).
 */
class FournisseurController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Fournisseur::with('user')->withCount('produits');

        return $this->success($query->latest('created_at')->paginate(paginate_per_page($request)));
    }

    public function show(Fournisseur $fournisseur): JsonResponse
    {
        $fournisseur->load('user');
        $produitIds = Produit::where('fournisseur_id', $fournisseur->user_id)->pluck('id');

        $produitsPlusVendus = LigneCommande::whereIn('produit_id', $produitIds)
            ->selectRaw('produit_id, SUM(quantite) as quantite_totale')
            ->groupBy('produit_id')
            ->orderByDesc('quantite_totale')
            ->with('produit:id,nom_produit,prix')
            ->limit(5)
            ->get();

        return $this->success([
            'fournisseur' => $fournisseur,
            'statistiques' => [
                'produits_total' => $produitIds->count(),
                'commandes_recues' => LigneCommande::whereIn('produit_id', $produitIds)->distinct('commande_id')->count('commande_id'),
                'commandes_livrees' => Commande::whereHas('lignes', fn ($q) => $q->whereIn('produit_id', $produitIds))
                    ->where('statut_commande', STATUT_COMMANDE_LIVREE)->count(),
                'commandes_annulees' => Commande::whereHas('lignes', fn ($q) => $q->whereIn('produit_id', $produitIds))
                    ->where('statut_commande', STATUT_COMMANDE_ANNULEE)->count(),
                'produits_plus_vendus' => $produitsPlusVendus,
            ],
        ]);
    }

    /**
     * Catalogue complet de ce fournisseur (tous statuts) — vue Centre des
     * opérations, distincte de la vue catalogue public/coordinateur de
     * ProduitController::index() qui restreint par statut.
     */
    public function produits(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $produits = Produit::where('fournisseur_id', $fournisseur->user_id)
            ->with(['images', 'categorie'])
            ->latest('date_ajout')
            ->paginate(paginate_per_page($request));

        return $this->success($produits);
    }

    /**
     * Commandes contenant au moins une ligne d'un produit de ce fournisseur —
     * même convention de filtre que CommandeController::filtrerPourCoordinateur()
     * (?statut=tous, ?statut=a,b,c), sans restriction par défaut ici (vue
     * fournisseur du Centre des opérations, pas une file d'attente).
     */
    public function commandes(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $query = Commande::whereHas('lignes', fn ($q) => $q->whereHas('produit', fn ($q2) => $q2->where('fournisseur_id', $fournisseur->user_id)))
            ->with(['client.user', 'lignes.produit.images', 'livraison']);

        if ($request->filled('statut') && $request->string('statut')->toString() !== 'tous') {
            $query->whereIn('statut_commande', explode(',', $request->string('statut')));
        }

        return $this->success($query->latest('date_commande')->paginate(paginate_per_page($request)));
    }

    /**
     * Solde signé (positif = OrdiSpace doit au fournisseur) + historique des
     * mouvements — même forme que MoiController::portefeuille() (client).
     */
    public function portefeuille(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        return $this->success([
            'solde' => $fournisseur->solde_portefeuille,
            'taux_commission' => $fournisseur->taux_commission,
            'transactions' => $fournisseur->transactionsPortefeuille()
                ->with('acteur:id,nom,prenom')
                ->latest('date_transaction')
                ->paginate(paginate_per_page($request)),
        ]);
    }

    /**
     * Paiement enregistré manuellement (Admin ou Coordinateur) — débite le
     * solde, avec traçabilité de l'acteur (cf. Fournisseur::debiterPortefeuille()).
     */
    public function enregistrerPaiement(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        $transaction = $fournisseur->debiterPortefeuille(
            (float) $data['montant'],
            $data['motif'] ?? 'Paiement enregistré',
            $request->user()->id
        );

        return $this->success($transaction, status: 201);
    }

    /**
     * Ajuste le taux de commission négocié avec ce fournisseur — décision
     * contractuelle réservée à l'Administrateur (PERMISSION_FOURNISSEURS_COMMISSION_GERER).
     */
    public function modifierCommission(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $data = $request->validate([
            'taux_commission' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $fournisseur->update($data);

        return $this->success($fournisseur->fresh());
    }
}
