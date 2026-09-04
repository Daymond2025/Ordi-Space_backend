<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Adresse;
use App\Models\Commande;
use App\Models\ConsultationCommande;
use App\Models\Fournisseur;
use App\Models\LigneCommande;
use App\Models\Message;
use App\Models\Produit;
use App\Models\TransactionPortefeuilleFournisseur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de consultation/gestion Fournisseur — Espace Coordinateur,
 * Centre des opérations (produits, commandes, portefeuille financier).
 */
class FournisseurController extends Controller
{
    /**
     * Liste fournisseurs — Centre des opérations. Compte les commandes par
     * sous-requête corrélée (pas de relation directe Fournisseur -> Commande,
     * elle passe par lignes_commande.produit_id -> produits.fournisseur_id) :
     * commandes_en_attente_count alimente le badge de traitement, alors que
     * commandes_total_count (tous statuts) alimente le sous-titre "X Commandes".
     */
    public function index(Request $request): JsonResponse
    {
        $commandesDuFournisseur = fn () => Commande::selectRaw('count(distinct commandes.id)')
            ->join('lignes_commande', 'lignes_commande.commande_id', '=', 'commandes.id')
            ->join('produits', 'produits.id', '=', 'lignes_commande.produit_id')
            ->whereColumn('produits.fournisseur_id', 'fournisseurs.user_id');

        $derniereCommande = Commande::selectRaw('max(commandes.date_commande)')
            ->join('lignes_commande', 'lignes_commande.commande_id', '=', 'commandes.id')
            ->join('produits', 'produits.id', '=', 'lignes_commande.produit_id')
            ->whereColumn('produits.fournisseur_id', 'fournisseurs.user_id');

        // Portefeuille du coordinateur (onglet "Fournisseurs") — montant dû à
        // ce fournisseur, pas juste un compte de commandes.
        $montantEnAttente = TransactionPortefeuilleFournisseur::selectRaw('COALESCE(SUM(montant), 0)')
            ->whereColumn('fournisseur_id', 'fournisseurs.user_id')
            ->where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)
            ->where('statut', STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE);

        $query = Fournisseur::with('user')
            ->withCount('produits')
            ->addSelect([
                'commandes_en_attente_count' => $commandesDuFournisseur()->where('commandes.statut_commande', STATUT_COMMANDE_EN_ATTENTE),
                'commandes_total_count' => $commandesDuFournisseur(),
                'derniere_commande_le' => $derniereCommande,
                'montant_en_attente' => $montantEnAttente,
            ])
            ->withCasts([
                'commandes_en_attente_count' => 'integer',
                'commandes_total_count' => 'integer',
                'montant_en_attente' => 'float',
            ]);

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
     *
     * `compteurs` regroupe les 9 statuts réels en 5 buckets (onglet "Commandes
     * uniquement" du détail fournisseur) — toujours calculé sur l'ensemble des
     * commandes du fournisseur, indépendamment du filtre `?statut=` actif, pour
     * que les 5 chips affichent en permanence le bon total.
     */
    public function commandes(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $baseQuery = fn () => Commande::whereHas('lignes', fn ($q) => $q->whereHas('produit', fn ($q2) => $q2->where('fournisseur_id', $fournisseur->user_id)));

        $comptesParStatut = $baseQuery()
            ->selectRaw('statut_commande, count(*) as total')
            ->groupBy('statut_commande')
            ->pluck('total', 'statut_commande');

        $compteurs = [
            'nouvelle' => (int) ($comptesParStatut[STATUT_COMMANDE_EN_ATTENTE] ?? 0),
            'en_cours' => (int) $comptesParStatut->only([
                STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_EN_PREPARATION, STATUT_COMMANDE_EN_LIVRAISON,
            ])->sum(),
            'attention' => (int) $comptesParStatut->only([
                STATUT_COMMANDE_REPORTEE, STATUT_COMMANDE_CLIENT_INJOIGNABLE, STATUT_COMMANDE_NUMERO_INCORRECT,
            ])->sum(),
            'livree' => (int) ($comptesParStatut[STATUT_COMMANDE_LIVREE] ?? 0),
            'annulee' => (int) ($comptesParStatut[STATUT_COMMANDE_ANNULEE] ?? 0),
        ];

        $query = $baseQuery()->with(['client.user', 'lignes.produit.images', 'livraison.adresse.localite']);

        if ($request->filled('statut') && $request->string('statut')->toString() !== 'tous') {
            $query->whereIn('statut_commande', explode(',', $request->string('statut')));
        }

        $paginator = $query->latest('date_commande')->paginate(paginate_per_page($request));

        // Badge non-lu par commande — même calcul batché (pas de N+1) que
        // MessageController::conversationProduit(), scopé à ce fournisseur.
        $commandeIds = collect($paginator->items())->pluck('id');

        $consultationsCommande = ConsultationCommande::where('user_id', $request->user()->id)
            ->whereIn('commande_id', $commandeIds)
            ->pluck('consulte_le', 'commande_id');

        $messagesNonLusParCommande = Message::whereIn('commande_id', $commandeIds)
            ->where('auteur_id', '!=', $request->user()->id)
            ->get(['id', 'commande_id', 'date_envoi'])
            ->groupBy('commande_id');

        $paginator->getCollection()->transform(function (Commande $commande) use ($consultationsCommande, $messagesNonLusParCommande) {
            $consulteLe = $consultationsCommande->get($commande->id);
            $nouvellesActivites = ($messagesNonLusParCommande->get($commande->id) ?? collect())
                ->filter(fn ($m) => ! $consulteLe || $m->date_envoi > $consulteLe)
                ->count();

            return $this->formaterCarteCommandeFournisseur($commande, $nouvellesActivites);
        });

        return $this->success([
            'commandes' => $paginator,
            'compteurs' => $compteurs,
        ]);
    }

    /**
     * Même forme que MessageController::formaterCommandeCarte() (type frontend
     * `CommandeCarte` partagé) mais sans `dernier_suivi` — pas de journal
     * d'audit affiché sur cette liste, on évite la requête inutile.
     */
    private function formaterCarteCommandeFournisseur(Commande $commande, int $nouvellesActivites): array
    {
        $produit = $commande->lignes->first()?->produit;

        return [
            'commande_id' => $commande->id,
            'photo' => $produit?->images->first()?->url_image,
            'nom_produit' => $produit?->nom_produit,
            'description' => null,
            'nom_client' => trim(($commande->client?->user?->prenom ?? '').' '.($commande->client?->user?->nom ?? '')),
            'zone_localite' => $this->formaterZoneLocalite($commande->livraison?->adresse),
            'telephone' => $commande->client?->user?->telephone,
            'derniere_action' => $commande->updated_at,
            'statut' => $commande->statut_commande,
            'nouvelles_activites' => $nouvellesActivites,
            'dernier_suivi' => null,
        ];
    }

    /** "Ville, Localité" — même logique que MessageController::formaterZoneLocalite(). */
    private function formaterZoneLocalite(?Adresse $adresse): ?string
    {
        if (! $adresse) {
            return null;
        }

        return $adresse->localite ? "{$adresse->ville}, {$adresse->localite->nom}" : $adresse->ville;
    }

    /**
     * Solde signé (positif = OrdiSpace doit au fournisseur) + ventes (crédits
     * uniquement — les débits sont le mécanisme de règlement, pas des lignes
     * affichables avec produit/statut) — même forme de base que
     * MoiController::portefeuille() (client), enrichie du statut de règlement
     * par ligne et de `total_en_attente` pour le bouton "Payer tout" (écran
     * Portefeuille fournisseur, Centre des opérations).
     */
    public function portefeuille(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        [$debut, $fin] = $this->resoudrePeriodePortefeuille($request);

        $totalEnAttente = (float) $fournisseur->transactionsPortefeuille()
            ->where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)
            ->where('statut', STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE)
            ->sum('montant');

        $transactions = $fournisseur->transactionsPortefeuille()
            ->where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)
            ->when($debut && $fin, fn ($q) => $q->whereBetween('date_transaction', [$debut, $fin]))
            ->with('commande.lignes.produit.images')
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
            ];
        });

        return $this->success([
            'solde' => $fournisseur->solde_portefeuille,
            'taux_commission' => $fournisseur->taux_commission,
            'total_en_attente' => $totalEnAttente,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Paiement enregistré manuellement (Admin ou Coordinateur) — débite le
     * solde, avec traçabilité de l'acteur (cf. Fournisseur::debiterPortefeuille()).
     * Débit libre, sans lien avec des crédits précis — distinct de payerTout()
     * qui règle spécifiquement les ventes en_attente.
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
     * "Payer tout" — bascule toutes les ventes en_attente de ce fournisseur en
     * payé et crée un débit unique du total (Fournisseur::payerCreditsEnAttente()).
     */
    public function payerTout(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $data = $request->validate([
            'reference_paiement' => ['required', 'string', 'max:255'],
        ]);

        $totalEnAttente = (float) $fournisseur->transactionsPortefeuille()
            ->where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)
            ->where('statut', STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE)
            ->sum('montant');

        abort_if($totalEnAttente <= 0, 422, "Aucune vente en attente de paiement pour ce fournisseur.");

        $transaction = $fournisseur->payerCreditsEnAttente($request->user()->id, $data['reference_paiement']);

        return $this->success($transaction, status: 201);
    }

    /**
     * @return array{0: ?\Illuminate\Support\Carbon, 1: ?\Illuminate\Support\Carbon}
     */
    private function resoudrePeriodePortefeuille(Request $request): array
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
     * Tableau de bord de ventes d'UN fournisseur — écran "Statistiques" du
     * détail fournisseur. Le chiffre d'affaires reprend exactement la même
     * base que le solde de portefeuille() (crédits de la période), pour que
     * les deux écrans restent cohérents entre eux.
     */
    public function statistiques(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        [$debut, $fin] = $this->resoudrePeriodePortefeuille($request);
        $produitIds = Produit::where('fournisseur_id', $fournisseur->user_id)->pluck('id');

        $creditsPeriode = $fournisseur->transactionsPortefeuille()
            ->where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)
            ->when($debut && $fin, fn ($q) => $q->whereBetween('date_transaction', [$debut, $fin]))
            ->get();

        $chiffreAffaires = (float) $creditsPeriode->sum('montant');
        $commissionOrdispace = $this->commissionCombinee($creditsPeriode);

        $commandesPeriode = fn () => Commande::whereHas('lignes', fn ($q) => $q->whereIn('produit_id', $produitIds))
            ->when($debut && $fin, fn ($q) => $q->whereBetween('date_commande', [$debut, $fin]));

        $lignesPeriode = fn () => LigneCommande::whereIn('produit_id', $produitIds)
            ->when($debut && $fin, fn ($q) => $q->whereHas('commande', fn ($q2) => $q2->whereBetween('date_commande', [$debut, $fin])));

        $produitsPlusVendus = LigneCommande::whereIn('produit_id', $produitIds)
            ->when($debut && $fin, fn ($q) => $q->whereHas('commande', fn ($q2) => $q2->whereBetween('date_commande', [$debut, $fin])))
            ->selectRaw('produit_id, SUM(quantite) as quantite_totale, SUM(quantite * COALESCE(prix_partenaire_unitaire, prix_unitaire)) as montant_total')
            ->groupBy('produit_id')
            ->orderByDesc('quantite_totale')
            ->with('produit.images')
            ->limit(5)
            ->get()
            ->map(fn ($ligne) => [
                'produit_id' => $ligne->produit_id,
                'nom_produit' => $ligne->produit?->nom_produit,
                'photo' => $ligne->produit?->images->first()?->url_image,
                'quantite_vendue' => (int) $ligne->quantite_totale,
                'montant' => (float) $ligne->montant_total,
            ]);

        return $this->success([
            'chiffre_affaires' => $chiffreAffaires,
            'croissance_pourcentage' => $this->croissancePourcentage($fournisseur, $request, $chiffreAffaires),
            'produits_vendus' => (int) $lignesPeriode()->sum('quantite'),
            'produits_distincts_vendus' => (int) $lignesPeriode()->distinct('produit_id')->count('produit_id'),
            'commandes_recues' => (clone $commandesPeriode())->count(),
            'commandes_livrees' => (clone $commandesPeriode())->where('statut_commande', STATUT_COMMANDE_LIVREE)->count(),
            'commandes_annulees' => (clone $commandesPeriode())->where('statut_commande', STATUT_COMMANDE_ANNULEE)->count(),
            'commission_ordispace' => $commissionOrdispace,
            'produits_plus_vendus' => $produitsPlusVendus,
        ]);
    }

    /**
     * commission_prelevee des crédits (ancien flux, taux_commission) + marge
     * négociée des lignes du nouveau flux (prix_vente - prix_partenaire) —
     * combine les deux systèmes de prix car commission_prelevee vaut toujours
     * 0 sur un crédit du nouveau flux (la marge n'y est jamais déduite du
     * fournisseur, cf. Commande::crediterFournisseursSiEligible()).
     */
    /** @param \Illuminate\Database\Eloquent\Collection<int, TransactionPortefeuilleFournisseur> $creditsPeriode */
    private function commissionCombinee($creditsPeriode): float
    {
        $commissionAncienFlux = (float) $creditsPeriode->sum('commission_prelevee');

        $commandeIds = $creditsPeriode->pluck('commande_id')->filter();
        $commissionNouveauFlux = (float) LigneCommande::whereIn('commande_id', $commandeIds)
            ->whereNotNull('prix_partenaire_unitaire')
            ->selectRaw('SUM((prix_unitaire - prix_partenaire_unitaire) * quantite) as total')
            ->value('total');

        return $commissionAncienFlux + $commissionNouveauFlux;
    }

    /**
     * Compare le chiffre d'affaires de la période sélectionnée à celui de la
     * période équivalente précédente — null si "tout" (pas de période
     * précédente comparable) ou si la précédente est à 0 (division impossible).
     */
    private function croissancePourcentage(Fournisseur $fournisseur, Request $request, float $chiffreAffairesActuel): ?float
    {
        $periode = $request->string('periode')->toString() ?: 'tout';

        [$debutPrecedent, $finPrecedent] = match ($periode) {
            'aujourd_hui' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'semaine' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'semaine_derniere' => [now()->subWeeks(2)->startOfWeek(), now()->subWeeks(2)->endOfWeek()],
            'mois' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            default => [null, null],
        };

        if (! $debutPrecedent || ! $finPrecedent) {
            return null;
        }

        $chiffreAffairesPrecedent = (float) $fournisseur->transactionsPortefeuille()
            ->where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)
            ->whereBetween('date_transaction', [$debutPrecedent, $finPrecedent])
            ->sum('montant');

        if ($chiffreAffairesPrecedent <= 0) {
            return null;
        }

        return round((($chiffreAffairesActuel - $chiffreAffairesPrecedent) / $chiffreAffairesPrecedent) * 100, 1);
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
