<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Produit\StoreProduitRequest;
use App\Http\Requests\Produit\ValiderProduitRequest;
use App\Models\FraisLivraisonProduit;
use App\Models\ImageProduit;
use App\Models\Localite;
use App\Models\Produit;
use App\Models\ValidationProduit;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProduitController extends Controller
{
    /**
     * Catalogue : chaque rôle voit un sous-ensemble différent — c'est le
     * même endpoint pour les 6 apps, la donnée renvoyée s'adapte au jeton.
     */
    /**
     * Public (pas de compte requis) : navigation libre du catalogue. Un
     * visiteur anonyme ou un client ne voit que les produits validés ;
     * fournisseur/coordinateur/admin voient leur périmètre habituel.
     */
    public function index(Request $request): JsonResponse
    {
        // Route publique (hors auth:sanctum) : $request->user() ne résout
        // jamais le Bearer token ici (guard par défaut = web) — current_user()
        // force la résolution via le guard sanctum, sans rejeter les invités.
        $user = current_user();
        $query = Produit::with(['images', 'categorie']);

        if ($user?->type_utilisateur === ROLE_FOURNISSEUR) {
            $query->where('fournisseur_id', $user->id);
        } elseif ($user?->type_utilisateur === ROLE_COORDINATEUR) {
            $this->filtrerCatalogueCoordinateur($query, $request);
        } elseif (! in_array($user?->type_utilisateur, [ROLE_ADMINISTRATEUR], true)) {
            $query->where('statut_produit', STATUT_PRODUIT_VALIDE);
        }

        if ($request->filled('categorie_id')) {
            $query->where('categorie_id', $request->integer('categorie_id'));
        }

        if ($request->filled('booste')) {
            $query->where('est_booste', $request->boolean('booste'));
        }

        if ($request->filled('fournisseur_id')) {
            $query->where('fournisseur_id', $request->integer('fournisseur_id'));
        }

        if ($request->filled('recherche')) {
            $query->where('nom_produit', 'LIKE', '%'.$request->string('recherche').'%');
        }

        return $this->success($query->latest('date_ajout')->paginate(paginate_per_page($request)));
    }

    /**
     * Écran Catalogue (Espace Coordinateur) : total/boostés/indisponibles,
     * scopés comme index() (un fournisseur ne voit que ses propres compteurs).
     */
    public function statistiques(Request $request): JsonResponse
    {
        $user = $request->user();
        $base = Produit::query();

        if ($user->type_utilisateur === ROLE_FOURNISSEUR) {
            $base->where('fournisseur_id', $user->id);
        }

        return $this->success([
            'total' => (clone $base)->count(),
            'boostes' => (clone $base)->where('est_booste', true)->count(),
            'indisponibles' => (clone $base)->where('quantite_stock', '<=', 0)->count(),
        ]);
    }

    /**
     * Vue catalogue du coordinateur : par défaut la file d'attente de
     * validation (inchangé) ; ?statut=tous lève la restriction (même
     * convention que CommandeController::filtrerPourCoordinateur()) ;
     * ?statut=indisponible filtre sur le stock (pas de colonne dédiée — la
     * disponibilité est dérivée de quantite_stock) ; sinon un statut précis.
     */
    private function filtrerCatalogueCoordinateur($query, Request $request): void
    {
        $statut = $request->string('statut')->toString();

        match (true) {
            $statut === '' => $query->whereIn('statut_produit', [STATUT_PRODUIT_EN_ATTENTE, STATUT_PRODUIT_CORRIGE]),
            $statut === 'tous' => null,
            $statut === 'indisponible' => $query->where('quantite_stock', '<=', 0),
            default => $query->where('statut_produit', $statut),
        };
    }

    public function show(Request $request, Produit $produit): JsonResponse
    {
        $produit->load(['images', 'categorie', 'fournisseur']);

        // Route publique (hors auth:sanctum) : $request->user() ne résout
        // jamais le Bearer token ici (guard par défaut = web) — current_user()
        // force la résolution via le guard sanctum, sans rejeter les invités
        // qui consultent un produit déjà publié.
        $user = current_user();
        $peutVoirNonValide = $user && (
            $user->type_utilisateur === ROLE_ADMINISTRATEUR
            || $user->type_utilisateur === ROLE_COORDINATEUR
            || $produit->fournisseur_id === $user->id
        );

        if ($produit->statut_produit !== STATUT_PRODUIT_VALIDE && ! $peutVoirNonValide) {
            abort(404);
        }

        return $this->success($produit);
    }

    public function store(StoreProduitRequest $request): JsonResponse
    {
        // L'Admin et le Coordinateur publient directement (pas de fournisseur,
        // pas de cycle de validation — ils sont eux-mêmes les validateurs) ;
        // le Fournisseur reste soumis au cycle en_attente → coordinateur, inchangé.
        $estAutoPublie = in_array($request->user()->type_utilisateur, [ROLE_ADMINISTRATEUR, ROLE_COORDINATEUR], true);

        $produit = DB::transaction(function () use ($request, $estAutoPublie) {
            $produit = Produit::create([
                ...$request->safe()->except(['images', 'frais_livraison']),
                'fournisseur_id' => $estAutoPublie ? null : $request->user()->id,
                'statut_produit' => $estAutoPublie ? STATUT_PRODUIT_VALIDE : STATUT_PRODUIT_EN_ATTENTE,
                'date_ajout' => now(),
            ]);

            $this->stockerImages($produit, $request->file('images', []));
            $this->stockerFraisLivraison($produit, $request->input('frais_livraison', []), estMiseAJour: false);

            return $produit;
        });

        return $this->success($produit->load('images'), status: 201);
    }

    public function update(StoreProduitRequest $request, Produit $produit): JsonResponse
    {
        $this->authorize('update', $produit);

        $produit->update([
            ...$request->safe()->except(['images', 'frais_livraison']),
            // Un produit corrigé après rejet repasse en file d'attente du coordinateur.
            'statut_produit' => $produit->statut_produit === STATUT_PRODUIT_REJETE ? STATUT_PRODUIT_CORRIGE : $produit->statut_produit,
        ]);

        $this->stockerImages($produit, $request->file('images', []));

        // Remplacement complet, pas fusion : un barème est soumis comme un
        // tout (comme prix/quantite_stock) — s'il est omis, rien ne change.
        if ($request->has('frais_livraison')) {
            $this->stockerFraisLivraison($produit, $request->input('frais_livraison', []), estMiseAJour: true);
        }

        return $this->success($produit->fresh(['images', 'categorie']));
    }

    /**
     * Suppression définitive — refusée si le produit a déjà été commandé au
     * moins une fois (la ligne de commande doit conserver son historique).
     */
    public function destroy(Produit $produit): JsonResponse
    {
        $this->authorize('delete', $produit);

        if ($produit->lignesCommande()->exists()) {
            throw ValidationException::withMessages([
                'produit' => ['Ce produit a déjà été commandé et ne peut plus être supprimé.'],
            ]);
        }

        DB::transaction(function () use ($produit) {
            foreach ($produit->images as $image) {
                Storage::disk(IMAGE_PRODUIT_DISQUE)->delete($image->cheminStockage());
            }

            $produit->delete();
        });

        return $this->success(['message' => 'Produit supprimé.']);
    }

    /**
     * Ajoute des images à un produit déjà existant, sans toucher au reste
     * de la fiche (utile pour compléter un catalogue après coup).
     */
    public function ajouterImages(Request $request, Produit $produit): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->any(['update', 'modifierFiche'], $produit), 403);

        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.IMAGE_PRODUIT_MAX_PAR_ENVOI],
            'images.*' => ['file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
        ]);

        $this->stockerImages($produit, $request->file('images', []));

        return $this->success($produit->fresh('images'), status: 201);
    }

    public function supprimerImage(Request $request, Produit $produit, ImageProduit $image): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->any(['update', 'modifierFiche'], $produit), 403);
        abort_unless($image->produit_id === $produit->id, 404);

        Storage::disk(IMAGE_PRODUIT_DISQUE)->delete($image->cheminStockage());
        $image->delete();

        return $this->success(['message' => 'Image supprimée.']);
    }

    /**
     * Décision du coordinateur — cœur du cycle de validation produit.
     */
    public function valider(ValiderProduitRequest $request, Produit $produit): JsonResponse
    {
        $this->authorize('valider', $produit);

        DB::transaction(function () use ($request, $produit) {
            ValidationProduit::create([
                'produit_id' => $produit->id,
                'coordinateur_id' => $request->user()->id,
                'decision' => $request->string('decision'),
                'motif_rejet' => $request->input('motif_rejet'),
                'date_validation' => now(),
            ]);

            $produit->update(['statut_produit' => $request->string('decision')->toString()]);
        });

        return $this->success($produit->fresh(['images', 'categorie']));
    }

    /**
     * Publication après négociation — fixe le prix de vente réel (distinct
     * du prix partenaire `prix`, jamais modifié ici) et la répartition de
     * commission, puis publie. Même acteurs/règle que valider() : coordinateur
     * ou admin, produit pas déjà valide.
     */
    public function publier(Request $request, Produit $produit): JsonResponse
    {
        $this->authorize('valider', $produit);

        $data = $request->validate([
            'prix_vente' => ['required', 'numeric', 'min:0'],
            'commission_agent' => ['nullable', 'numeric', 'min:0'],
            'commission_apporteur' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $produit, $data) {
            ValidationProduit::create([
                'produit_id' => $produit->id,
                'coordinateur_id' => $request->user()->id,
                'decision' => DECISION_VALIDATION_VALIDE,
                'date_validation' => now(),
            ]);

            $produit->update([
                'prix_vente' => $data['prix_vente'],
                'commission_agent' => $data['commission_agent'] ?? 1000,
                'commission_apporteur' => $data['commission_apporteur'] ?? round(($data['prix_vente'] - $produit->prix) * 0.25, 2),
                'statut_produit' => STATUT_PRODUIT_VALIDE,
            ]);
        });

        return $this->success($produit->fresh(['images', 'categorie']));
    }

    /**
     * Produit "boosté" (Espace Coordinateur, Catalogue) — reçoit un booléen
     * explicite plutôt qu'un simple toggle, pour rester idempotent contre
     * un double-clic/double-soumission.
     */
    public function basculerBoost(Request $request, Produit $produit): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_PRODUITS_BOOSTER), 403);

        $data = $request->validate(['est_booste' => ['required', 'boolean']]);
        $produit->update(['est_booste' => $data['est_booste']]);

        return $this->success($produit->fresh(['images', 'categorie']));
    }

    /**
     * Gestion étroite du stock/disponibilité — Coordinateur (n'importe quel
     * produit) ou Fournisseur (le sien uniquement, cf. ProduitPolicy::gererStock()).
     * Volontairement plus restreint que update() : aucun autre champ n'est modifiable ici.
     */
    public function modifierStock(Request $request, Produit $produit): JsonResponse
    {
        $this->authorize('gererStock', $produit);

        $data = $request->validate(['quantite_stock' => ['required', 'integer', 'min:0']]);
        $produit->update($data);

        return $this->success($produit->fresh(['images', 'categorie']));
    }

    /**
     * Ajustement du prix par le Coordinateur pendant la revue — même
     * périmètre que valider() (ProduitPolicy::modifierPrix()) : uniquement
     * tant que le produit n'est pas encore publié.
     */
    public function modifierPrix(Request $request, Produit $produit): JsonResponse
    {
        $this->authorize('modifierPrix', $produit);

        $data = $request->validate(['prix' => ['required', 'numeric', 'min:0']]);
        $produit->update($data);

        return $this->success($produit->fresh(['images', 'categorie']));
    }

    /**
     * Correctifs de fiche par le Coordinateur (menu ☰ → "Modifier") — nom,
     * description, catégorie, caractéristiques, garantie, cadeaux.
     * Volontairement disjoint de update() : jamais de prix/stock ici (voir
     * ProduitPolicy::modifierFiche()).
     */
    public function modifierFiche(Request $request, Produit $produit): JsonResponse
    {
        $this->authorize('modifierFiche', $produit);

        $data = $request->validate([
            'nom_produit' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'categorie_id' => ['sometimes', 'exists:categories,id'],
            'processeur' => ['nullable', 'string', 'max:150'],
            'memoire_ram' => ['nullable', 'string', 'max:150'],
            'stockage' => ['nullable', 'string', 'max:150'],
            'taille' => ['nullable', 'string', 'max:150'],
            'systeme_exploitation' => ['nullable', 'string', 'max:150'],
            'carte_graphique' => ['nullable', 'string', 'max:150'],
            'duree_garantie_mois' => ['nullable', 'integer', 'min:0'],
            'cadeaux' => ['nullable', 'array'],
            'cadeaux.*' => ['string', 'max:100'],
        ]);

        $produit->update($data);

        return $this->success($produit->fresh(['images', 'categorie']));
    }

    /**
     * Prévisualise les frais de livraison d'un produit vers une localité,
     * avant de créer la commande — flux "création par copier-coller" (Espace
     * Coordinateur) : permet de découvrir l'absence de barème (même message
     * qu'à la création réelle, voir CommandeController::calculerFraisLivraison())
     * avant de faire saisir tout le reste au coordinateur.
     */
    public function previsualiserFraisLivraison(Request $request, Produit $produit): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMANDES_CREER), 403);

        $data = $request->validate(['localite_id' => ['required', 'exists:localites,id']]);

        if ($produit->estNumerique()) {
            return $this->success(['frais_livraison' => 0.0]);
        }

        $frais = FraisLivraisonProduit::where('produit_id', $produit->id)
            ->where('localite_id', $data['localite_id'])
            ->value('montant');

        if ($frais === null) {
            $localite = Localite::find($data['localite_id']);

            throw ValidationException::withMessages([
                'localite_id' => ["Aucun frais de livraison n'est défini pour « {$produit->nom_produit} » vers « {$localite?->nom} »."],
            ]);
        }

        return $this->success(['frais_livraison' => (float) $frais]);
    }

    /**
     * Enregistre les fichiers uploadés sur le disque local "public" et crée
     * les lignes IMAGE_PRODUIT correspondantes, en respectant le plafond
     * total d'images par produit (IMAGE_PRODUIT_MAX_TOTAL).
     *
     * @param  UploadedFile[]  $fichiers
     */
    private function stockerImages(Produit $produit, array $fichiers): void
    {
        if ($fichiers === []) {
            return;
        }

        $dejaPresentes = $produit->images()->count();

        if ($dejaPresentes + count($fichiers) > IMAGE_PRODUIT_MAX_TOTAL) {
            throw ValidationException::withMessages([
                'images' => ['Un produit ne peut pas avoir plus de '.IMAGE_PRODUIT_MAX_TOTAL.' images au total.'],
            ]);
        }

        foreach (array_values($fichiers) as $index => $fichier) {
            $chemin = $fichier->store(IMAGE_PRODUIT_DOSSIER, IMAGE_PRODUIT_DISQUE);

            ImageProduit::create([
                'produit_id' => $produit->id,
                'url_image' => $chemin,
                'ordre_affichage' => $dejaPresentes + $index,
            ]);
        }
    }

    /**
     * Enregistre le barème de frais de livraison par localité. Remplacement
     * complet en mise à jour (pas de fusion) — un barème est soumis comme un
     * tout, contrairement aux images qui ont un endpoint d'ajout dédié.
     *
     * @param  array<int, array{localite_id: int, montant: float}>  $lignesFrais
     */
    private function stockerFraisLivraison(Produit $produit, array $lignesFrais, bool $estMiseAJour): void
    {
        if ($lignesFrais === []) {
            return;
        }

        if ($produit->estNumerique()) {
            throw ValidationException::withMessages([
                'frais_livraison' => ['Un produit à livraison numérique ne peut pas avoir de frais de livraison.'],
            ]);
        }

        DB::transaction(function () use ($produit, $lignesFrais, $estMiseAJour) {
            if ($estMiseAJour) {
                $produit->fraisLivraison()->delete();
            }

            foreach ($lignesFrais as $ligne) {
                FraisLivraisonProduit::create([
                    'produit_id' => $produit->id,
                    'localite_id' => $ligne['localite_id'],
                    'montant' => $ligne['montant'],
                ]);
            }
        });
    }
}
