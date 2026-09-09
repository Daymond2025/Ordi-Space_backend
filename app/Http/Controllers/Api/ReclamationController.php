<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\JournalAudit;
use App\Models\PreuveReclamation;
use App\Models\Reclamation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ReclamationController extends Controller
{
    /**
     * Les 5 entités qui peuvent déposer une réclamation, toutes traitées par
     * le coordinateur — distinct des déclarations de panne SAV (une
     * réclamation couvre un litige général : commande, livraison,
     * facturation…, pas une panne matérielle à diagnostiquer).
     */
    private const ROLES_AUTORISES_A_DEPOSER = [
        ROLE_CLIENT, ROLE_FOURNISSEUR, ROLE_COMMERCIAL, ROLE_LIVREUR, ROLE_TECHNICIEN_MAINTENANCE,
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Reclamation::with(['auteur', 'client.user', 'commande']);

        if (in_array($user->type_utilisateur, self::ROLES_AUTORISES_A_DEPOSER, true)) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        // Filtre par type d'entité auteur — utilisé par l'écran Réclamations
        // de l'Espace Coordinateur (Admin_Web) pour distinguer les 5 entités,
        // et par /clients/reclamations (Admin_Web) pour rester scopé aux
        // clients uniquement (ancien format qui suppose client.user.*).
        if ($request->filled('type_auteur')) {
            $query->whereHas('auteur', fn ($q) => $q->where('type_utilisateur', $request->string('type_auteur')));
        }

        return $this->success($query->latest('date_reclamation')->paginate(paginate_per_page($request)));
    }

    public function show(Request $request, Reclamation $reclamation): JsonResponse
    {
        $user = $request->user();
        $estFileur = in_array($user->type_utilisateur, self::ROLES_AUTORISES_A_DEPOSER, true);
        abort_unless(! $estFileur || $reclamation->user_id === $user->id, 403);

        $reclamation->load([
            'auteur', 'client.user', 'preuves',
            'commande.lignes.produit.images', 'commande.lignes.produit.fournisseur.user',
        ]);

        // Une commande est traitée comme mono-produit pour les aperçus dans
        // tout ce codebase (cf. Commande::versApercu()) — même logique ici
        // pour la carte "Produits concernés" de l'écran détail.
        $produit = $reclamation->commande?->lignes->first()?->produit;
        $fournisseur = $produit?->fournisseur;

        return $this->success([
            'id' => $reclamation->id,
            'titre' => $reclamation->titre,
            'sujet' => $reclamation->sujet,
            'description' => $reclamation->description,
            'statut' => $reclamation->statut,
            'reponse_admin' => $reclamation->reponse_admin,
            'date_reclamation' => $reclamation->date_reclamation,
            'date_traitement' => $reclamation->date_traitement,
            'auteur' => $reclamation->auteur,
            'commande' => $reclamation->commande ? [
                'id' => $reclamation->commande->id,
                'produit' => $produit ? [
                    'id' => $produit->id,
                    'nom_produit' => $produit->nom_produit,
                    'prix' => $produit->prix_vente ?? $produit->prix,
                    'photo' => $produit->images->first()?->url_image,
                    'disponible' => $produit->quantite_stock > 0,
                ] : null,
                'fournisseur' => $fournisseur ? [
                    'user_id' => $fournisseur->user_id,
                    'nom_entreprise' => $fournisseur->nom_entreprise,
                    'nom' => $fournisseur->user->nom,
                    'prenom' => $fournisseur->user->prenom,
                    'telephone' => $fournisseur->user->telephone,
                ] : null,
            ] : null,
            'preuves' => $reclamation->preuves,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->type_utilisateur, self::ROLES_AUTORISES_A_DEPOSER, true), 403);

        $data = $request->validate([
            'commande_id' => ['nullable', 'exists:commandes,id'],
            'sujet' => ['required', 'string', 'max:150'],
            'titre' => ['nullable', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        // La vérification de propriété de la commande n'a de sens que pour un
        // client (les 4 autres entités n'ont pas de lien "commande à eux" de
        // la même façon — livreur/fournisseur/commercial interviennent sur
        // des commandes d'autrui par construction).
        if (! empty($data['commande_id']) && $user->type_utilisateur === ROLE_CLIENT) {
            $commande = Commande::findOrFail($data['commande_id']);
            abort_unless($commande->client_id === $user->id, 403, 'Cette commande ne vous appartient pas.');
        }

        $reclamation = Reclamation::create([
            ...$data,
            // Titre facultatif à l'écriture (Ordi'Space_App_Mobile n'envoie pas
            // encore ce champ) — on retombe sur le sujet par défaut.
            'titre' => $data['titre'] ?? $data['sujet'],
            'user_id' => $user->id,
            'client_id' => $user->type_utilisateur === ROLE_CLIENT ? $user->id : null,
            'statut' => STATUT_RECLAMATION_NOUVELLE,
            'date_reclamation' => now(),
        ]);

        JournalAudit::enregistrer(
            $user->id,
            ACTION_RECLAMATION_CREEE,
            'reclamation',
            "A déposé une réclamation : « {$data['sujet']} »"
        );

        return $this->success($reclamation, status: 201);
    }

    /**
     * Répondre à une réclamation (changer son statut et ajouter un commentaire
     * de réponse).
     */
    public function repondre(Request $request, Reclamation $reclamation): JsonResponse
    {
        $data = $request->validate([
            'statut' => ['required', Rule::in([
                STATUT_RECLAMATION_EN_COURS, STATUT_RECLAMATION_RESOLUE, STATUT_RECLAMATION_REJETEE,
            ])],
            'reponse_admin' => ['required', 'string', 'max:2000'],
        ]);

        $reclamation->update([
            ...$data,
            'date_traitement' => now(),
        ]);

        return $this->success($reclamation->fresh(['auteur', 'client.user', 'commande']));
    }

    /**
     * Ajoute une ou plusieurs preuves (photos) à une réclamation — "Espace
     * Preuve" de l'écran détail. Réservé à l'auteur de la réclamation ou au
     * coordinateur/admin (même triptyque validation/stockage/accessor que
     * ProduitController::ajouterImages()).
     */
    public function ajouterPreuve(Request $request, Reclamation $reclamation): JsonResponse
    {
        $this->autoriserGestionPreuves($request, $reclamation);

        $data = $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
        ]);

        $preuves = collect($data['images'])->map(fn ($fichier) => $reclamation->preuves()->create([
            'fichier' => $fichier->store(RECLAMATION_PREUVE_DOSSIER, IMAGE_PRODUIT_DISQUE),
        ]));

        return $this->success($preuves, status: 201);
    }

    public function supprimerPreuve(Request $request, Reclamation $reclamation, PreuveReclamation $preuve): JsonResponse
    {
        $this->autoriserGestionPreuves($request, $reclamation);
        abort_unless($preuve->reclamation_id === $reclamation->id, 404);

        Storage::disk(IMAGE_PRODUIT_DISQUE)->delete($preuve->cheminStockage());
        $preuve->delete();

        return $this->success(['message' => 'Preuve supprimée.']);
    }

    private function autoriserGestionPreuves(Request $request, Reclamation $reclamation): void
    {
        $user = $request->user();
        $estAuteur = $reclamation->user_id === $user->id;

        abort_unless($estAuteur || $user->can(PERMISSION_RECLAMATIONS_GERER), 403);
    }
}
