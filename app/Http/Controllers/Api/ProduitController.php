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
        $user = $request->user();
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

        return $this->success($query->latest('date_ajout')->paginate(paginate_per_page($request)));
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

        $user = $request->user();
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
        // L'Admin publie directement des accessoires/logiciels (pas de
        // fournisseur, pas de cycle de validation) ; le Fournisseur reste
        // soumis au cycle en_attente → coordinateur, inchangé.
        $estAdmin = $request->user()->type_utilisateur === ROLE_ADMINISTRATEUR;

        $produit = DB::transaction(function () use ($request, $estAdmin) {
            $produit = Produit::create([
                ...$request->safe()->except(['images', 'frais_livraison']),
                'fournisseur_id' => $estAdmin ? null : $request->user()->id,
                'statut_produit' => $estAdmin ? STATUT_PRODUIT_VALIDE : STATUT_PRODUIT_EN_ATTENTE,
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
        $this->authorize('update', $produit);

        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.IMAGE_PRODUIT_MAX_PAR_ENVOI],
            'images.*' => ['file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
        ]);

        $this->stockerImages($produit, $request->file('images', []));

        return $this->success($produit->fresh('images'), status: 201);
    }

    public function supprimerImage(Request $request, Produit $produit, ImageProduit $image): JsonResponse
    {
        $this->authorize('update', $produit);
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
