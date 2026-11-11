<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Partenaire\CommandeController as CommandePartenaireController;
use App\Http\Controllers\Api\Partenaire\ControleurPartenaire;
use App\Http\Controllers\Controller;
use App\Models\CommandePartenaire;
use App\Models\MouvementStockPartenaire;
use App\Models\Partenaire;
use App\Models\Produit;
use App\Models\StockPartenaire;
use App\Models\TarifPartenaire;
use App\Services\Partenaire\StockPartenaireService;
use App\Services\Partenaire\TarificationPartenaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestion des partenaires par l'Admin : profil, villes couvertes, remise
 * par défaut, prix de cession d'exception, stock et ventes. La création du
 * compte passe par UtilisateurController::provisionner().
 */
class PartenaireController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Partenaire::with(['user:id,nom,prenom,telephone,statut_compte,photo', 'localites:id,nom'])
            ->withCount(['commandes as commandes_livrees_count' => fn ($q) => $q->where('statut', STATUT_COMMANDE_LIVREE)]);

        if ($request->filled('localite_id')) {
            $query->couvrant($request->integer('localite_id'));
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(fn ($w) => $w->where('nom_boutique', 'like', $q)
                ->orWhereHas('user', fn ($u) => $u->where('nom', 'like', $q)->orWhere('telephone', 'like', $q)));
        }

        return $this->success($query->latest('created_at')->paginate(paginate_per_page($request)));
    }

    public function show(Partenaire $partenaire): JsonResponse
    {
        $partenaire->load(['user:id,nom,prenom,telephone,email,statut_compte,photo', 'localites:id,nom']);

        $livrees = CommandePartenaire::with('lignes')->where('partenaire_id', $partenaire->user_id)->where('statut', STATUT_COMMANDE_LIVREE)->get();

        return $this->success([
            'partenaire' => $partenaire,
            'statistiques' => [
                'commandes_livrees' => $livrees->count(),
                'chiffre_affaires' => (float) $livrees->sum('montant_total'),
                'benefice_partenaire' => $livrees->sum(fn (CommandePartenaire $c) => $c->benefice()),
                // Ce qu'Ordi'Space a encaissé sur ces ventes (prix de cession).
                'montant_ordispace' => (float) $livrees->sum(fn (CommandePartenaire $c) => $c->lignes->sum(fn ($l) => (float) $l->prix_cession_unitaire * $l->quantite)),
                'articles_en_stock' => (int) StockPartenaire::where('partenaire_id', $partenaire->user_id)->sum('quantite'),
                'tarifs_exception' => $partenaire->tarifs()->count(),
            ],
        ]);
    }

    public function modifier(Request $request, Partenaire $partenaire): JsonResponse
    {
        $data = $request->validate([
            'nom_boutique' => ['sometimes', 'string', 'max:150'],
            'adresse_boutique' => ['nullable', 'string', 'max:255'],
            'remise_defaut' => ['sometimes', 'numeric', 'min:0', 'max:90'],
            'localite_ids' => ['sometimes', 'array', 'min:1'],
            'localite_ids.*' => ['integer', 'distinct', 'exists:localites,id'],
        ]);

        DB::transaction(function () use ($partenaire, $data) {
            $partenaire->update(collect($data)->except('localite_ids')->all());
            if (isset($data['localite_ids'])) {
                $partenaire->localites()->sync($data['localite_ids']);
            }
        });

        return $this->show($partenaire->fresh());
    }

    /** Catalogue avec le prix de cession effectif du partenaire (?q=, ?exceptions=1). */
    public function tarifs(Request $request, Partenaire $partenaire): JsonResponse
    {
        $query = Produit::with(['images', 'categorie'])->where('statut_produit', STATUT_PRODUIT_VALIDE);

        if ($request->filled('q')) {
            $query->where('nom_produit', 'like', '%'.$request->string('q').'%');
        }
        if ($request->boolean('exceptions')) {
            $query->whereIn('id', $partenaire->tarifs()->select('produit_id'));
        }

        $page = $query->latest('id')->paginate(paginate_per_page($request));
        $produits = collect($page->items());
        $prix = TarificationPartenaire::prixCessionPour($partenaire, $produits);
        $exceptions = $partenaire->tarifs()->whereIn('produit_id', $produits->pluck('id'))->pluck('prix_cession', 'produit_id');

        $page->setCollection($produits->map(fn (Produit $p) => [
            ...ControleurPartenaire::presenterProduit($p),
            // L'Admin voit aussi le coût fournisseur, pour ne pas céder à perte.
            'prix_fournisseur' => (float) $p->prix,
            'prix_ordispace' => TarificationPartenaire::prixOrdispace($p),
            'prix_cession' => $prix[$p->id],
            'est_exception' => isset($exceptions[$p->id]),
        ]));

        return $this->success($page);
    }

    public function definirTarif(Request $request, Partenaire $partenaire, Produit $produit): JsonResponse
    {
        $data = $request->validate(['prix_cession' => ['required', 'numeric', 'min:1', 'max:999999999']]);

        $tarif = TarifPartenaire::updateOrCreate(
            ['partenaire_id' => $partenaire->user_id, 'produit_id' => $produit->id],
            ['prix_cession' => round((float) $data['prix_cession'])],
        );

        return $this->success($tarif);
    }

    /** Retour à la remise par défaut pour ce produit. */
    public function supprimerTarif(Partenaire $partenaire, Produit $produit): JsonResponse
    {
        TarifPartenaire::where('partenaire_id', $partenaire->user_id)->where('produit_id', $produit->id)->delete();

        return $this->success(['prix_cession' => TarificationPartenaire::prixCession($partenaire, $produit)]);
    }

    public function stock(Partenaire $partenaire): JsonResponse
    {
        return $this->success(StockPartenaire::with(['produit.images', 'produit.categorie'])
            ->where('partenaire_id', $partenaire->user_id)->where('quantite', '>', 0)->orderByDesc('quantite')->get()
            ->map(fn (StockPartenaire $s) => ['produit' => ControleurPartenaire::presenterProduit($s->produit), 'quantite' => $s->quantite]));
    }

    public function mouvements(Request $request, Partenaire $partenaire): JsonResponse
    {
        return $this->success(MouvementStockPartenaire::with('produit:id,nom_produit')
            ->where('partenaire_id', $partenaire->user_id)->latest('id')->paginate(paginate_per_page($request)));
    }

    /** Correction d'inventaire dans les deux sens (quantité signée), toujours motivée. */
    public function ajusterStock(Request $request, Partenaire $partenaire, Produit $produit): JsonResponse
    {
        $data = $request->validate([
            'quantite' => ['required', 'integer', 'not_in:0', 'min:-100000', 'max:100000'],
            'motif' => ['required', 'string', 'max:255'],
        ]);

        $stock = DB::transaction(fn () => StockPartenaireService::mouvement(
            $partenaire->user_id, $produit->id, $data['quantite'], MOUVEMENT_STOCK_AJUSTEMENT,
            ['motif' => $data['motif'], 'auteur_id' => $request->user()->id],
        ));

        return $this->success($stock);
    }

    public function commandes(Request $request, Partenaire $partenaire): JsonResponse
    {
        $query = CommandePartenaire::with(['client:id,nom,prenom,telephone', 'localite:id,nom', 'lignes.produit.images', 'lignes.produit.categorie'])
            ->where('partenaire_id', $partenaire->user_id);

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        $page = $query->latest('id')->paginate(paginate_per_page($request));
        $page->setCollection(collect($page->items())->map(fn (CommandePartenaire $c) => CommandePartenaireController::presenter($c)));

        return $this->success($page);
    }
}
