<?php

namespace App\Http\Controllers\Api\Coordinateur;

use App\Http\Controllers\Controller;
use App\Models\Fournisseur;
use App\Models\TransactionPortefeuilleFournisseur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portefeuille global du coordinateur (bouton "Paiement" de la BottomNav) —
 * agrège les ventes (crédits) de TOUS les fournisseurs, contrairement à
 * FournisseurController::portefeuille() qui est scopé à un seul. Même base
 * de calcul (crédits = revenu, statut en_attente/paye) pour rester cohérent
 * avec les écrans par-fournisseur déjà construits. Pas de "payer tout" ici —
 * le règlement groupé se fait depuis le portefeuille de chaque fournisseur.
 */
class PortefeuilleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$debut, $fin] = $this->resoudrePeriode($request);

        $soldeGeneral = (float) Fournisseur::sum('solde_portefeuille');

        // 3 cartes stats de l'en-tête — toujours sur l'ensemble de la période,
        // indépendamment du filtre `?statut=` actif sur la liste en dessous
        // (même convention que les compteurs de FournisseurController::commandes()).
        $creditsPeriode = fn () => TransactionPortefeuilleFournisseur::where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)
            ->when($debut && $fin, fn ($q) => $q->whereBetween('date_transaction', [$debut, $fin]));

        $totalGeneral = (float) $creditsPeriode()->sum('montant');
        $totalEnAttente = (float) $creditsPeriode()->where('statut', STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE)->sum('montant');
        $totalPaye = (float) $creditsPeriode()->where('statut', STATUT_TRANSACTION_PORTEFEUILLE_PAYE)->sum('montant');

        $transactions = $creditsPeriode()
            ->when(
                $request->filled('statut') && $request->string('statut')->toString() !== 'tous',
                fn ($q) => $q->where('statut', $request->string('statut')->toString())
            )
            ->with(['fournisseur:user_id,nom_entreprise', 'commande.lignes.produit.images'])
            ->latest('date_transaction')
            ->paginate(paginate_per_page($request));

        $transactions->getCollection()->transform(function (TransactionPortefeuilleFournisseur $transaction) {
            $produit = $transaction->commande?->lignes->first()?->produit;

            return [
                'id' => $transaction->id,
                'montant' => (float) $transaction->montant,
                'statut' => $transaction->statut,
                'date_transaction' => $transaction->date_transaction,
                'nom_produit' => $produit?->nom_produit,
                'photo' => $produit?->images->first()?->url_image,
                'nom_fournisseur' => $transaction->fournisseur?->nom_entreprise,
            ];
        });

        return $this->success([
            'solde_general' => $soldeGeneral,
            'total_general' => $totalGeneral,
            'total_en_attente' => $totalEnAttente,
            'total_paye' => $totalPaye,
            'transactions' => $transactions,
        ]);
    }

    /**
     * @return array{0: ?\Illuminate\Support\Carbon, 1: ?\Illuminate\Support\Carbon}
     */
    private function resoudrePeriode(Request $request): array
    {
        return match ($request->string('periode')->toString() ?: 'tout') {
            'aujourd_hui' => [now()->startOfDay(), now()->endOfDay()],
            'semaine' => [now()->startOfWeek(), now()->endOfWeek()],
            'semaine_derniere' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'mois' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [null, null],
        };
    }

    /**
     * Détail d'une transaction — ouvert au tap sur une carte des écrans
     * portefeuille (par-fournisseur ou global). `prix_vente_total` (ce que le
     * client a payé) est distinct de `montant` (ce qui est dû/payé au
     * fournisseur, déjà stocké sur la transaction) — deux figures différentes
     * affichées côte à côte dans le mockup.
     */
    public function show(TransactionPortefeuilleFournisseur $transaction): JsonResponse
    {
        $transaction->load(['fournisseur', 'commande.lignes.produit.images']);
        $ligne = $transaction->commande?->lignes->first();
        $produit = $ligne?->produit;

        return $this->success($this->formaterDetail($transaction, $ligne, $produit));
    }

    /**
     * Reçu PDF — pas de mockup d'écran/format fourni, mise en page sobre
     * reprenant les mêmes informations que l'écran détail.
     */
    public function recu(TransactionPortefeuilleFournisseur $transaction)
    {
        $transaction->load(['fournisseur', 'commande.lignes.produit.images']);
        $ligne = $transaction->commande?->lignes->first();
        $produit = $ligne?->produit;

        $pdf = \Pdf::loadView('recus.transaction', $this->formaterDetail($transaction, $ligne, $produit));

        return $pdf->download("recu-{$transaction->id}.pdf");
    }

    private function formaterDetail(TransactionPortefeuilleFournisseur $transaction, $ligne, $produit): array
    {
        return [
            'id' => $transaction->id,
            // Saisie par le coordinateur au moment du règlement réel hors app
            // (Fournisseur::payerCreditsEnAttente()) — reste null tant que la
            // vente est en_attente, pas de valeur fabriquée.
            'reference_paiement' => $transaction->reference_paiement,
            'montant' => (float) $transaction->montant,
            'statut' => $transaction->statut,
            'date_transaction' => $transaction->date_transaction,
            'nom_produit' => $produit?->nom_produit,
            'photo' => $produit?->images->first()?->url_image,
            'prix_vente_total' => $ligne ? (float) $ligne->prix_unitaire * $ligne->quantite : null,
            'nom_fournisseur' => $transaction->fournisseur?->nom_entreprise,
            'nom_gerant' => $transaction->fournisseur?->nom_gerant,
            'telephone_fournisseur' => $transaction->fournisseur?->contact_pro,
        ];
    }
}
