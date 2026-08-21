<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Produit\StoreProduitRequest;
use App\Http\Requests\Produit\ValiderProduitRequest;
use App\Models\ImageProduit;
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
            $query->when($request->filled('statut'), fn ($q) => $q->where('statut_produit', $request->string('statut')))
                ->when(! $request->filled('statut'), fn ($q) => $q->whereIn('statut_produit', [STATUT_PRODUIT_EN_ATTENTE, STATUT_PRODUIT_CORRIGE]));
        } elseif (! in_array($user?->type_utilisateur, [ROLE_ADMINISTRATEUR], true)) {
            $query->where('statut_produit', STATUT_PRODUIT_VALIDE);
        }

        if ($request->filled('categorie_id')) {
            $query->where('categorie_id', $request->integer('categorie_id'));
        }

        return $this->success($query->latest('date_ajout')->paginate(paginate_per_page($request)));
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
                ...$request->safe()->except('images'),
                'fournisseur_id' => $estAdmin ? null : $request->user()->id,
                'statut_produit' => $estAdmin ? STATUT_PRODUIT_VALIDE : STATUT_PRODUIT_EN_ATTENTE,
                'date_ajout' => now(),
            ]);

            $this->stockerImages($produit, $request->file('images', []));

            return $produit;
        });

        return $this->success($produit->load('images'), status: 201);
    }

    public function update(StoreProduitRequest $request, Produit $produit): JsonResponse
    {
        $this->authorize('update', $produit);

        $produit->update([
            ...$request->safe()->except('images'),
            // Un produit corrigé après rejet repasse en file d'attente du coordinateur.
            'statut_produit' => $produit->statut_produit === STATUT_PRODUIT_REJETE ? STATUT_PRODUIT_CORRIGE : $produit->statut_produit,
        ]);

        $this->stockerImages($produit, $request->file('images', []));

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
}
