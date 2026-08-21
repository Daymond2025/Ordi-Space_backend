<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commande\StoreCommandeRequest;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Garantie;
use App\Models\JournalAudit;
use App\Models\LigneCommande;
use App\Models\Livraison;
use App\Models\CanalVente;
use App\Models\Privilege;
use App\Models\Produit;
use App\Models\User;
use App\Models\UtilisationPrivilege;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommandeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Commande::with(['client.user', 'commercial.user', 'canalVente', 'lignes.produit.images', 'lignes.produit.categorie', 'livraison']);

        match ($user->type_utilisateur) {
            ROLE_CLIENT => $query->where('client_id', $user->id),
            ROLE_COMMERCIAL => $query->where('commercial_id', $user->id),
            ROLE_COORDINATEUR => $query->when(
                ! $request->filled('statut'),
                fn ($q) => $q->where('statut_commande', STATUT_COMMANDE_EN_ATTENTE)
            )->when(
                $request->filled('statut'),
                fn ($q) => $q->where('statut_commande', $request->string('statut'))
            ),
            ROLE_LIVREUR => $query->whereHas('livraison', fn ($q) => $q->where('livreur_id', $user->id)),
            default => null, // administrateur : aucun filtre.
        };

        return $this->success($query->latest('date_commande')->paginate(paginate_per_page($request)));
    }

    public function show(Request $request, Commande $commande): JsonResponse
    {
        $this->autoriserAcces($request, $commande);

        return $this->success($commande->load(['lignes.produit.images', 'lignes.produit.categorie', 'livraison', 'paiement', 'canalVente']));
    }

    /**
     * Étape 1-2 du processus de commande : le client (ou le commercial en
     * son nom) passe la commande ; elle attend ensuite la validation du
     * coordinateur (statut "en_attente").
     */
    public function store(StoreCommandeRequest $request): JsonResponse
    {
        $commande = DB::transaction(function () use ($request) {
            $produits = Produit::whereIn('id', collect($request->input('lignes'))->pluck('produit_id'))
                ->where('statut_produit', STATUT_PRODUIT_VALIDE)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $montantTotal = 0;
            $lignesAPersister = [];
            $necessiteLivraison = false;

            foreach ($request->input('lignes') as $ligne) {
                $produit = $produits->get($ligne['produit_id']);

                if (! $produit) {
                    throw ValidationException::withMessages([
                        'lignes' => ["Le produit #{$ligne['produit_id']} n'est pas disponible à la vente."],
                    ]);
                }

                if ($produit->quantite_stock < $ligne['quantite']) {
                    throw ValidationException::withMessages([
                        'lignes' => ["Stock insuffisant pour « {$produit->nom_produit} »."],
                    ]);
                }

                if (! $produit->estNumerique()) {
                    $necessiteLivraison = true;
                }

                $sousTotal = $produit->prix * $ligne['quantite'];
                $montantTotal += $sousTotal;

                $lignesAPersister[] = [
                    'produit_id' => $produit->id,
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => $produit->prix,
                ];

                $produit->decrement('quantite_stock', $ligne['quantite']);
            }

            // Un panier 100% numérique (logiciels) n'a rien à livrer ; sinon
            // une adresse valide est obligatoire.
            if ($necessiteLivraison && ! $request->filled('adresse_id')) {
                throw ValidationException::withMessages([
                    'adresse_id' => ['Une adresse de livraison est requise pour cette commande.'],
                ]);
            }

            $clientId = $request->clientIdResolu();

            [$privilege, $montantRemise] = $this->resoudrePrivilege(
                $request->input('code_promo'),
                $clientId,
                $montantTotal
            );

            $commande = Commande::create([
                'client_id' => $clientId,
                'commercial_id' => $this->resoudreCommercial($request->user()),
                'canal_vente_id' => $this->resoudreCanalVente($request),
                'privilege_id' => $privilege?->id,
                'parrain_id' => $this->resoudreParrain($request->input('code_parrainage'), $clientId),
                'livraison_gratuite_appliquee' => $this->estDeuxiemeCommandeEligible($clientId),
                'statut_commande' => STATUT_COMMANDE_EN_ATTENTE,
                'montant_total' => $montantTotal,
                'montant_remise' => $montantRemise,
                'date_commande' => now(),
            ]);

            foreach ($lignesAPersister as $ligne) {
                LigneCommande::create([...$ligne, 'commande_id' => $commande->id]);
            }

            if ($necessiteLivraison) {
                Livraison::create([
                    'commande_id' => $commande->id,
                    'adresse_id' => $request->integer('adresse_id'),
                    'statut_livraison' => STATUT_LIVRAISON_EN_PREPARATION,
                ]);
            }

            if ($privilege) {
                UtilisationPrivilege::create([
                    'privilege_id' => $privilege->id,
                    'client_id' => $clientId,
                    'commande_id' => $commande->id,
                    'montant_remise' => $montantRemise,
                    'date_utilisation' => now(),
                ]);
            }

            return $commande;
        });

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_COMMANDE_CREEE,
            'commande',
            "A passé une commande de {$commande->montant_total} CFA (n°{$commande->id})."
        );

        if ($commande->privilege_id) {
            JournalAudit::enregistrer(
                $commande->client_id,
                ACTION_PRIVILEGE_UTILISE,
                'privilege',
                "A utilisé un code promo sur la commande n°{$commande->id}."
            );
        }

        return $this->success($commande->load('lignes.produit'), status: 201);
    }

    /**
     * Étape 3 : le coordinateur valide la commande, ce qui débloque sa
     * préparation par le fournisseur.
     */
    public function valider(Request $request, Commande $commande): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMANDES_VALIDER), 403);

        if ($commande->statut_commande !== STATUT_COMMANDE_EN_ATTENTE) {
            throw ValidationException::withMessages([
                'statut_commande' => ['Cette commande a déjà été traitée.'],
            ]);
        }

        $estNumerique = ! $commande->livraison;

        $commande->update([
            'coordinateur_id' => $request->user()->id,
            'date_validation' => now(),
            // Une commande 100% numérique n'a rien à préparer ni à livrer :
            // elle est directement considérée "livrée" une fois validée.
            'statut_commande' => $estNumerique ? STATUT_COMMANDE_LIVREE : STATUT_COMMANDE_VALIDEE,
        ]);

        if ($estNumerique) {
            Garantie::genererPourCommande($commande);
        }

        return $this->success($commande->fresh());
    }

    /**
     * Étape 4 : le fournisseur signale que le colis est prêt — la livraison
     * devient visible dans le vivier des livreurs.
     */
    public function marquerPreparee(Request $request, Commande $commande): JsonResponse
    {
        $livraison = $commande->livraison;
        $estFournisseurConcerne = $commande->lignes()
            ->whereHas('produit', fn ($q) => $q->where('fournisseur_id', $request->user()->id))
            ->exists();

        abort_unless($livraison && $estFournisseurConcerne, 403);

        if ($commande->statut_commande !== STATUT_COMMANDE_VALIDEE) {
            throw ValidationException::withMessages([
                'statut_commande' => ['La commande doit être validée avant préparation.'],
            ]);
        }

        $commande->update(['statut_commande' => STATUT_COMMANDE_EN_PREPARATION]);
        $livraison->update(['statut_livraison' => STATUT_LIVRAISON_EN_ATTENTE_LIVREUR]);

        return $this->success($commande->fresh('livraison'));
    }

    /**
     * Un commercial humain enregistre sous son propre identifiant ; un client
     * qui commande lui-même depuis l'appli est rattaché à l'agent IA système
     * (aucun commercial humain n'intervient) — voir DatabaseSeeder::seedAgentIa().
     */
    private function resoudreCommercial(User $user): int
    {
        if ($user->type_utilisateur === ROLE_COMMERCIAL) {
            return $user->id;
        }

        $agentId = User::where('email', AGENT_IA_EMAIL)->value('id');

        abort_unless($agentId, 500, "Agent IA système introuvable — relancez le seeder.");

        return $agentId;
    }

    /**
     * Un commercial peut préciser le canal (Facebook, WhatsApp…) ; un client
     * qui commande lui-même passe toujours par la boutique de l'appli.
     */
    private function resoudreCanalVente(Request $request): int
    {
        if ($request->user()->type_utilisateur === ROLE_COMMERCIAL && $request->filled('canal_vente_id')) {
            return $request->integer('canal_vente_id');
        }

        $canalId = CanalVente::where('nom_canal', CANAL_VENTE_BOUTIQUE_APPLICATION)->value('id');

        abort_unless($canalId, 500, 'Canal "Boutique application" introuvable — relancez le seeder.');

        return $canalId;
    }

    /**
     * Vérifie et calcule l'effet d'un code Privilège Space, s'il est fourni.
     *
     * @return array{0: ?Privilege, 1: float}
     */
    private function resoudrePrivilege(?string $code, int $clientId, float $montantTotal): array
    {
        if (! $code) {
            return [null, 0.0];
        }

        $privilege = Privilege::where('code_promo', $code)->first();

        if (! $privilege || ! $privilege->actif || ! $privilege->estValidePourDate()) {
            throw ValidationException::withMessages([
                'code_promo' => ['Ce code promo est invalide ou expiré.'],
            ]);
        }

        if ($privilege->limite_utilisation_par_client !== null) {
            $dejaUtilise = UtilisationPrivilege::where('privilege_id', $privilege->id)
                ->where('client_id', $clientId)
                ->count();

            if ($dejaUtilise >= $privilege->limite_utilisation_par_client) {
                throw ValidationException::withMessages([
                    'code_promo' => ['Ce privilège a déjà été utilisé le nombre maximal de fois autorisé.'],
                ]);
            }
        }

        return [$privilege, $privilege->calculerRemise($montantTotal)];
    }

    /**
     * Résout le code de parrainage personnel d'un client ("Carte invitation").
     * Le filleul le saisit ; le parrain sera crédité sur son portefeuille à
     * la livraison — voir LivraisonController::livrer().
     */
    private function resoudreParrain(?string $code, int $clientId): ?int
    {
        if (! $code) {
            return null;
        }

        $parrain = Client::where('code_parrainage', $code)->first();

        if (! $parrain) {
            throw ValidationException::withMessages([
                'code_parrainage' => ['Code de parrainage introuvable.'],
            ]);
        }

        if ($parrain->user_id === $clientId) {
            throw ValidationException::withMessages([
                'code_parrainage' => ['Vous ne pouvez pas utiliser votre propre code de parrainage.'],
            ]);
        }

        return $parrain->user_id;
    }

    /**
     * "Carte free" : livraison gratuite automatique à la 2e commande d'un
     * client, si l'Admin a activé ce privilège dans le catalogue. Purement
     * informatif tant qu'aucun frais de livraison n'existe dans le modèle.
     */
    private function estDeuxiemeCommandeEligible(int $clientId): bool
    {
        $privilegeActif = Privilege::where('type_privilege', TYPE_PRIVILEGE_LIVRAISON_GRATUITE)
            ->where('actif', true)
            ->exists();

        if (! $privilegeActif) {
            return false;
        }

        $nombreCommandesPrecedentes = Commande::where('client_id', $clientId)->count();

        return ($nombreCommandesPrecedentes + 1) === SEUIL_COMMANDE_LIVRAISON_GRATUITE;
    }

    private function autoriserAcces(Request $request, Commande $commande): void
    {
        $user = $request->user();

        $autorise = match ($user->type_utilisateur) {
            ROLE_CLIENT => $commande->client_id === $user->id,
            ROLE_COMMERCIAL => $commande->commercial_id === $user->id,
            ROLE_LIVREUR => $commande->livraison?->livreur_id === $user->id,
            ROLE_COORDINATEUR, ROLE_ADMINISTRATEUR => true,
            default => false,
        };

        abort_unless($autorise, 403);
    }
}
