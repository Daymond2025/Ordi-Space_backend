<?php

namespace App\Http\Controllers\Api\Partenaire;

use App\Models\CommandePartenaire;
use App\Models\MouvementStockPartenaire;
use App\Models\OffrePartenaire;
use App\Models\Produit;
use App\Models\StockPartenaire;
use App\Services\FiltresCatalogue;
use App\Services\Partenaire\FilPartenaire;
use App\Services\Partenaire\StockPartenaireService;
use App\Services\Partenaire\TarificationPartenaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Espace Partenaire : profil + tableau de bord, catalogue Ordi'Space avec
 * son prix de cession, ses prix de revente et son stock.
 */
class EspacePartenaireController extends ControleurPartenaire
{
    /**
     * Écran "Profil" (même écran que le Fournisseur) : fiche boutique +
     * gérant. "Zone couverte" = ses villes, fixées par l'Admin.
     */
    public function profil(Request $request): JsonResponse
    {
        $partenaire = $this->partenaire($request)->load(['user', 'localites:id,nom']);

        return $this->success([
            'id' => $partenaire->user_id,
            'nom' => $partenaire->user->nom,
            'prenom' => $partenaire->user->prenom,
            'email' => $partenaire->user->email,
            'telephone' => $partenaire->user->telephone,
            'photo' => $partenaire->user->photo,
            'nom_boutique' => $partenaire->nom_boutique,
            'adresse_boutique' => $partenaire->adresse_boutique,
            // Sans téléphone pro renseigné, le numéro de connexion sert de contact.
            'contact_pro' => $partenaire->contact_pro ?? $partenaire->user->telephone,
            'horaires_ouverture' => $partenaire->horaires_ouverture,
            'lien_maps' => $partenaire->lien_maps,
            'zone_couverte' => $partenaire->localites->pluck('nom')->implode(', ') ?: null,
            'nom_gerant' => $partenaire->nom_gerant,
            'telephone_gerant' => $partenaire->telephone_gerant,
            'created_at' => $partenaire->created_at,
        ]);
    }

    /** "Modifier mon profil" — nom de boutique, villes et remise restent fixés par l'Admin. */
    public function modifierProfil(Request $request): JsonResponse
    {
        $partenaire = $this->partenaire($request);

        $partenaire->update($request->validate([
            'adresse_boutique' => ['nullable', 'string', 'max:255'],
            'contact_pro' => ['nullable', 'string', 'max:30'],
            'horaires_ouverture' => ['nullable', 'string', 'max:255'],
            'lien_maps' => ['nullable', 'url:https', 'max:500'],
            'nom_gerant' => ['nullable', 'string', 'max:150'],
            'telephone_gerant' => ['nullable', 'string', 'max:30'],
        ]));

        return $this->profil($request);
    }

    /**
     * Écran "Statistiques" (Profil → Statistique), même écran que le
     * Fournisseur. ?periode= aujourd_hui | semaine (défaut) | semaine_derniere
     * | mois | tout. Ventes et chiffre d'affaires comptés à la livraison ;
     * commandes reçues/validées/annulées/en attente selon leur date de
     * création et leur statut actuel.
     */
    public function statistiques(Request $request): JsonResponse
    {
        $id = $this->partenaire($request)->user_id;
        $this->validerPeriode($request);
        $periode = $request->string('periode')->toString() ?: 'semaine';
        $request->merge(['periode' => $periode]);
        [$debut, $fin] = resoudre_periode($request);

        $livreesEntre = fn ($d, $f) => CommandePartenaire::with('lignes.produit.images')->where('partenaire_id', $id)
            ->where('statut', STATUT_COMMANDE_LIVREE)
            ->when($d, fn ($q) => $q->whereBetween('livree_le', [$d, $f]))->get();

        $livrees = $livreesEntre($debut, $fin);
        $recues = CommandePartenaire::where('partenaire_id', $id)
            ->when($debut, fn ($q) => $q->whereBetween('created_at', [$debut, $fin]))->get(['id', 'statut']);

        $chiffreAffaires = (float) $livrees->sum('montant_total');

        [$debutPrecedent, $finPrecedent] = match ($periode) {
            'aujourd_hui' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'semaine' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'semaine_derniere' => [now()->subWeeks(2)->startOfWeek(), now()->subWeeks(2)->endOfWeek()],
            'mois' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            default => [null, null],
        };
        $precedent = $debutPrecedent ? (float) $livreesEntre($debutPrecedent, $finPrecedent)->sum('montant_total') : 0;

        $lignes = $livrees->flatMap->lignes;
        $plusVendus = $lignes->groupBy('produit_id')->map(fn ($groupe) => [
            'produit_id' => $groupe->first()->produit_id,
            'nom_produit' => $groupe->first()->produit?->nom_produit,
            'photo' => $groupe->first()->produit?->images->first()?->url_image,
            'quantite_vendue' => (int) $groupe->sum('quantite'),
            'montant' => (float) $groupe->sum(fn ($l) => (float) $l->prix_unitaire * $l->quantite),
        ])->sortByDesc('quantite_vendue')->values();

        return $this->success([
            'periode' => $periode,
            'chiffre_affaires' => $chiffreAffaires,
            'croissance_pourcentage' => $precedent > 0 ? round((($chiffreAffaires - $precedent) / $precedent) * 100, 1) : null,
            'produits_vendus' => (int) $lignes->sum('quantite'),
            'produits_distincts_vendus' => $plusVendus->count(),
            'commandes_recues' => $recues->count(),
            'commandes_validees' => $recues->whereIn('statut', STATUTS_CMD_PARTENAIRE_STOCK_SORTI)->count(),
            'commandes_annulees' => $recues->where('statut', STATUT_COMMANDE_ANNULEE)->count(),
            'commandes_en_attente' => $recues->whereIn('statut', [...FilPartenaire::BUCKETS['nouvelle'], ...FilPartenaire::BUCKETS['attention']])->count(),
            'benefice' => $livrees->sum(fn (CommandePartenaire $c) => $c->benefice()),
            'produits_plus_vendus' => $plusVendus->take(10),
        ]);
    }

    public function moi(Request $request): JsonResponse
    {
        $partenaire = $this->partenaire($request)->load(['user:id,nom,prenom,telephone,photo,statut_compte', 'localites:id,nom']);

        $livrees = CommandePartenaire::with('lignes')->where('partenaire_id', $partenaire->user_id)
            ->where('statut', STATUT_COMMANDE_LIVREE)->get();

        $parStatut = CommandePartenaire::where('partenaire_id', $partenaire->user_id)
            ->selectRaw('statut, count(*) as total')->groupBy('statut')->pluck('total', 'statut');

        return $this->success([
            'partenaire' => $partenaire,
            'statistiques' => [
                'commandes_par_statut' => $parStatut,
                'chiffre_affaires' => (float) $livrees->sum('montant_total'),
                'benefice' => $livrees->sum(fn (CommandePartenaire $c) => $c->benefice()),
                'articles_en_stock' => (int) StockPartenaire::where('partenaire_id', $partenaire->user_id)->sum('quantite'),
                'approvisionnements_en_cours' => $partenaire->approvisionnements()
                    ->whereNotIn('statut', [STATUT_APPRO_RECU, STATUT_APPRO_ANNULE])->count(),
            ],
        ]);
    }

    /**
     * Accueil "Space" de l'app Partenaire. ?periode= tout (défaut) |
     * aujourd_hui | semaine | mois — voir resoudre_periode(). Les ventes
     * comptent à la livraison, l'investissement au paiement validé par
     * Ordi'Space ; stock et nouvelles commandes sont toujours "maintenant".
     */
    public function accueil(Request $request): JsonResponse
    {
        $partenaire = $this->partenaire($request)->load('user');
        $id = $partenaire->user_id;
        $this->validerPeriode($request);
        $request->merge(['periode' => $request->input('periode', 'tout')]);
        [$debut, $fin] = resoudre_periode($request);
        $dansPeriode = fn ($q, string $colonne) => $debut ? $q->whereBetween($colonne, [$debut, $fin]) : $q;

        $livrees = $dansPeriode(CommandePartenaire::with('lignes')->where('partenaire_id', $id)->where('statut', STATUT_COMMANDE_LIVREE), 'livree_le')->get();

        $investissement = $dansPeriode(
            $partenaire->approvisionnements()->whereIn('statut', [STATUT_APPRO_PAIEMENT_VALIDE, STATUT_APPRO_EXPEDIE, STATUT_APPRO_RECU]),
            'paiement_valide_le'
        )->sum('montant_total');

        // Une ligne par produit commandé sur la période, activité la plus récente d'abord.
        $commandes = $dansPeriode(CommandePartenaire::with('lignes')->where('partenaire_id', $id), 'created_at')->get();
        $enStock = StockPartenaire::where('partenaire_id', $id)->where('quantite', '>', 0)->pluck('quantite', 'produit_id');
        $produits = Produit::with(['images', 'categorie'])->whereIn('id', $commandes->flatMap->lignes->pluck('produit_id')->unique())->get()->keyBy('id');

        $parProduit = $commandes
            ->flatMap(fn (CommandePartenaire $c) => $c->lignes->map(fn ($l) => ['produit_id' => $l->produit_id, 'commande' => $c]))
            ->groupBy('produit_id')
            ->map(function ($entrees, $produitId) use ($produits, $enStock) {
                $cmds = $entrees->pluck('commande')->unique('id');
                $statuts = $cmds->countBy('statut');

                return [
                    'produit' => self::presenterProduit($produits[$produitId]),
                    'en_stock' => (int) ($enStock[$produitId] ?? 0) > 0,
                    'commandes' => $cmds->count(),
                    'livrees' => $statuts[STATUT_COMMANDE_LIVREE] ?? 0,
                    'en_cours' => $cmds->reject(fn ($c) => $c->estFinale())->count(),
                    'annulees' => $statuts[STATUT_COMMANDE_ANNULEE] ?? 0,
                    // Pastille verte : commandes encore à accepter par le partenaire.
                    'nouvelles' => $statuts[STATUT_COMMANDE_EN_ATTENTE] ?? 0,
                    'derniere_activite' => $cmds->max('updated_at'),
                ];
            })
            ->sortByDesc('derniere_activite')
            ->values();

        return $this->success([
            'periode' => $request->input('periode'),
            'partenaire' => [
                'nom' => $partenaire->user->nom,
                'prenom' => $partenaire->user->prenom,
                'photo' => $partenaire->user->photo,
                'nom_boutique' => $partenaire->nom_boutique,
            ],
            'statistiques' => [
                'produits_en_stock' => $enStock->count(),
                'produits_vendus' => (int) $livrees->sum(fn (CommandePartenaire $c) => $c->lignes->sum('quantite')),
                'nouvelles_commandes' => CommandePartenaire::where('partenaire_id', $id)->where('statut', STATUT_COMMANDE_EN_ATTENTE)->count(),
                'chiffre_affaires' => (float) $livrees->sum('montant_total'),
                'investissement' => (float) $investissement,
                'benefice' => $livrees->sum(fn (CommandePartenaire $c) => $c->benefice()),
            ],
            'produits' => $parProduit,
        ]);
    }

    /**
     * Tout le catalogue Ordi'Space en vente. Filtres : ?q=, ?categorie_id=,
     * ?en_stock=1 (seulement ce que j'ai), ?en_vente=1 (offre active).
     */
    public function catalogue(Request $request): JsonResponse
    {
        $partenaire = $this->partenaire($request);
        $id = $partenaire->user_id;

        $query = Produit::with(['images', 'categorie'])->where('statut_produit', STATUT_PRODUIT_VALIDE);

        // Mêmes filtres que la marketplace client (famille, catégories, marque, RAM, stockage, taille…).
        FiltresCatalogue::appliquer($query, $request);

        if ($request->filled('q')) {
            $query->where(fn ($q) => $q->where('nom_produit', 'like', '%'.$request->string('q').'%')
                ->orWhere('marque', 'like', '%'.$request->string('q').'%'));
        }
        if ($request->filled('categorie_id')) {
            $query->where('categorie_id', $request->integer('categorie_id'));
        }
        if ($request->boolean('en_stock')) {
            $query->whereIn('id', StockPartenaire::where('partenaire_id', $id)->where('quantite', '>', 0)->select('produit_id'));
        }
        if ($request->boolean('en_vente')) {
            $query->whereIn('id', OffrePartenaire::where('partenaire_id', $id)->where('actif', true)->select('produit_id'));
        }

        $page = $query->latest('id')->paginate(paginate_per_page($request));
        $produits = collect($page->items());

        $prixCession = TarificationPartenaire::prixCessionPour($partenaire, $produits);
        $offres = OffrePartenaire::where('partenaire_id', $id)->whereIn('produit_id', $produits->pluck('id'))->get()->keyBy('produit_id');
        $stocks = StockPartenaire::where('partenaire_id', $id)->whereIn('produit_id', $produits->pluck('id'))->pluck('quantite', 'produit_id');

        $page->setCollection($produits->map(fn (Produit $p) => $this->ligneCatalogue($p, $prixCession[$p->id], $offres->get($p->id), (int) ($stocks[$p->id] ?? 0))));

        return $this->success($page);
    }

    public function produit(Request $request, Produit $produit): JsonResponse
    {
        abort_unless($produit->estVisibleALaVente(), 404);
        $partenaire = $this->partenaire($request);
        $produit->load(['images', 'categorie']);

        $offre = OffrePartenaire::where('partenaire_id', $partenaire->user_id)->where('produit_id', $produit->id)->first();

        return $this->success([
            ...self::caracteristiques($produit),
            ...$this->ligneCatalogue($produit, TarificationPartenaire::prixCession($partenaire, $produit), $offre, $partenaire->quantiteEnStock($produit->id)),
        ]);
    }

    /** Prix de revente : jamais sous le prix de cession (le partenaire vendrait à perte). */
    public function definirOffre(Request $request, Produit $produit): JsonResponse
    {
        abort_unless($produit->estVisibleALaVente(), 404);
        $partenaire = $this->partenaire($request);

        $data = $request->validate([
            'prix_revente' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'actif' => ['sometimes', 'boolean'],
        ]);

        $prixCession = TarificationPartenaire::prixCession($partenaire, $produit);
        if ((float) $data['prix_revente'] < $prixCession) {
            throw ValidationException::withMessages([
                'prix_revente' => ['Le prix de revente ne peut pas être inférieur à votre prix de cession ('.number_format($prixCession, 0, ',', ' ').' F).'],
            ]);
        }

        $offre = OffrePartenaire::updateOrCreate(
            ['partenaire_id' => $partenaire->user_id, 'produit_id' => $produit->id],
            ['prix_revente' => round((float) $data['prix_revente']), 'actif' => $data['actif'] ?? true],
        );

        return $this->success($offre);
    }

    public function stock(Request $request): JsonResponse
    {
        $partenaire = $this->partenaire($request);

        $stocks = StockPartenaire::with(['produit.images', 'produit.categorie'])
            ->where('partenaire_id', $partenaire->user_id)->where('quantite', '>', 0)
            ->orderByDesc('quantite')->get();

        $prixCession = TarificationPartenaire::prixCessionPour($partenaire, $stocks->pluck('produit'));

        return $this->success($stocks->map(fn (StockPartenaire $s) => [
            'produit' => self::presenterProduit($s->produit),
            'quantite' => $s->quantite,
            'prix_cession' => $prixCession[$s->produit_id],
            'valeur' => $prixCession[$s->produit_id] * $s->quantite,
        ])->values());
    }

    public function mouvements(Request $request): JsonResponse
    {
        $partenaire = $this->partenaire($request);

        $query = MouvementStockPartenaire::with(['produit:id,nom_produit'])->where('partenaire_id', $partenaire->user_id);
        if ($request->filled('produit_id')) {
            $query->where('produit_id', $request->integer('produit_id'));
        }

        return $this->success($query->latest('id')->paginate(paginate_per_page($request)));
    }

    /**
     * Sortie manuelle (casse, perte, usage interne) — uniquement à la
     * baisse : une entrée de stock passe toujours par un approvisionnement
     * payé à Ordi'Space (seul l'Admin peut corriger à la hausse).
     */
    public function ajusterStock(Request $request, Produit $produit): JsonResponse
    {
        $partenaire = $this->partenaire($request);

        $data = $request->validate([
            'quantite' => ['required', 'integer', 'min:1', 'max:100000'],
            'motif' => ['required', 'string', 'max:255'],
        ]);

        $stock = DB::transaction(fn () => StockPartenaireService::mouvement(
            $partenaire->user_id, $produit->id, -$data['quantite'], MOUVEMENT_STOCK_AJUSTEMENT,
            ['motif' => $data['motif'], 'auteur_id' => $request->user()->id],
        ));

        return $this->success($stock);
    }

    /** Paramètres de période : une valeur inconnue ou une date illisible est refusée (422), jamais une erreur serveur. */
    private function validerPeriode(Request $request): void
    {
        $request->validate([
            'periode' => ['nullable', Rule::in(['aujourd_hui', 'semaine', 'semaine_derniere', 'mois', 'tout'])],
            'date_debut' => ['nullable', 'required_with:date_fin', 'date'],
            'date_fin' => ['nullable', 'required_with:date_debut', 'date', 'after_or_equal:date_debut'],
        ]);
    }

    private function ligneCatalogue(Produit $produit, float $prixCession, ?OffrePartenaire $offre, int $stock): array
    {
        $prixRevente = $offre ? (float) $offre->prix_revente : null;

        return [
            ...self::presenterProduit($produit),
            'prix_ordispace' => TarificationPartenaire::prixOrdispace($produit),
            'prix_cession' => $prixCession,
            'prix_revente' => $prixRevente,
            'en_vente' => (bool) $offre?->actif,
            'benefice_unitaire' => $prixRevente !== null ? $prixRevente - $prixCession : null,
            'stock' => $stock,
        ];
    }
}
