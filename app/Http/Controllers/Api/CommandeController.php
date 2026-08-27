<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commande\StoreCommandeRequest;
use App\Models\Adresse;
use App\Models\Client;
use App\Models\Commande;
use App\Models\FraisLivraisonProduit;
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
    /**
     * Transitions "problème" autorisées pour un coordinateur, avant
     * validation définitive — voir traiterProbleme(). Un retour direct
     * problème→VALIDEE n'est jamais permis : il faut repasser par EN_ATTENTE
     * puis valider(), pour ne pas dupliquer ses effets de bord (Garantie,
     * raccourci commande 100% numérique).
     */
    private const TRANSITIONS_PROBLEME = [
        STATUT_COMMANDE_EN_ATTENTE => [
            STATUT_COMMANDE_REPORTEE, STATUT_COMMANDE_CLIENT_INJOIGNABLE, STATUT_COMMANDE_NUMERO_INCORRECT,
        ],
        STATUT_COMMANDE_REPORTEE => [STATUT_COMMANDE_EN_ATTENTE, STATUT_COMMANDE_ANNULEE],
        STATUT_COMMANDE_CLIENT_INJOIGNABLE => [STATUT_COMMANDE_EN_ATTENTE, STATUT_COMMANDE_ANNULEE],
        STATUT_COMMANDE_NUMERO_INCORRECT => [STATUT_COMMANDE_EN_ATTENTE, STATUT_COMMANDE_ANNULEE],
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Commande::with(['client.user', 'commercial.user', 'canalVente', 'lignes.produit.images', 'lignes.produit.categorie', 'livraison']);

        match ($user->type_utilisateur) {
            ROLE_CLIENT => $query->where('client_id', $user->id),
            ROLE_COMMERCIAL => $query->where('commercial_id', $user->id),
            ROLE_COORDINATEUR => $this->filtrerPourCoordinateur($query, $request),
            ROLE_LIVREUR => $query->whereHas('livraison', fn ($q) => $q->where('livreur_id', $user->id)),
            default => null, // administrateur : aucun filtre.
        };

        return $this->success($query->latest('date_commande')->paginate(paginate_per_page($request)));
    }

    /**
     * Comportement par défaut (sans ?statut=) inchangé : file d'attente des
     * commandes en_attente. Nouveau : ?statut=tous (aucune restriction) et
     * ?statut=a,b,c (plusieurs statuts), en plus du filtre à un seul statut
     * déjà existant — la vision globale du coordinateur (cahier des charges
     * Espace Coordinateur) sans casser un usage déjà en place côté Admin_Web.
     */
    private function filtrerPourCoordinateur($query, Request $request)
    {
        if (! $request->filled('statut')) {
            return $query->where('statut_commande', STATUT_COMMANDE_EN_ATTENTE);
        }

        $statut = $request->string('statut')->toString();

        if ($statut === 'tous') {
            return $query;
        }

        return $query->whereIn('statut_commande', explode(',', $statut));
    }

    public function show(Request $request, Commande $commande): JsonResponse
    {
        $this->autoriserAcces($request, $commande);

        $relations = ['lignes.produit.images', 'lignes.produit.categorie', 'livraison', 'paiement', 'canalVente'];

        if (in_array($request->user()->type_utilisateur, [ROLE_COORDINATEUR, ROLE_ADMINISTRATEUR], true)) {
            $relations = array_merge($relations, [
                'client.user', 'commercial.user', 'coordinateur.user', 'parrain.user', 'privilege',
                'lignes.produit.fournisseur.user', 'livraison.livreur.user',
            ]);
        }

        return $this->success($commande->load($relations));
    }

    /**
     * Un coordinateur signale un problème rencontré avant validation (numéro
     * incorrect, client injoignable, report) ou reprend/abandonne une
     * commande déjà signalée — voir TRANSITIONS_PROBLEME.
     */
    public function traiterProbleme(Request $request, Commande $commande): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMANDES_TRAITER), 403);

        $data = $request->validate([
            'statut_commande' => ['required', 'in:'.implode(',', [
                STATUT_COMMANDE_REPORTEE, STATUT_COMMANDE_CLIENT_INJOIGNABLE,
                STATUT_COMMANDE_NUMERO_INCORRECT, STATUT_COMMANDE_EN_ATTENTE, STATUT_COMMANDE_ANNULEE,
            ])],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        $cible = $data['statut_commande'];
        $autorises = self::TRANSITIONS_PROBLEME[$commande->statut_commande] ?? [];

        if (! in_array($cible, $autorises, true)) {
            throw ValidationException::withMessages([
                'statut_commande' => ["Transition de « {$commande->statut_commande} » vers « {$cible} » non autorisée."],
            ]);
        }

        DB::transaction(function () use ($commande, $cible) {
            // Seule une annulation libère le stock réservé — les autres
            // statuts "problème" laissent la commande vivante, en attente
            // de résolution (même logique que Admin\CommandeController::changerStatut()).
            if ($cible === STATUT_COMMANDE_ANNULEE) {
                foreach ($commande->lignes()->with('produit')->get() as $ligne) {
                    $ligne->produit?->increment('quantite_stock', $ligne->quantite);
                }
            }

            $commande->update(['statut_commande' => $cible]);
        });

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Statut de la commande n°{$commande->id} changé à « {$cible} » par un coordinateur."
                .(($data['motif'] ?? null) ? " Motif : {$data['motif']}." : ''),
            commandeId: $commande->id,
        );

        return $this->success($commande->fresh());
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

            $livraisonGratuite = $this->estDeuxiemeCommandeEligible($clientId);

            $fraisLivraison = $necessiteLivraison
                ? $this->calculerFraisLivraison(
                    Adresse::findOrFail($request->integer('adresse_id')),
                    $lignesAPersister,
                    $produits,
                    $livraisonGratuite
                )
                : 0.0;

            $commande = Commande::create([
                'client_id' => $clientId,
                'commercial_id' => $this->resoudreCommercial($request->user()),
                'canal_vente_id' => $this->resoudreCanalVente($request),
                'privilege_id' => $privilege?->id,
                'parrain_id' => $this->resoudreParrain($request->input('code_parrainage'), $clientId),
                'livraison_gratuite_appliquee' => $livraisonGratuite,
                'statut_commande' => STATUT_COMMANDE_EN_ATTENTE,
                'montant_total' => $montantTotal,
                'montant_remise' => $montantRemise,
                'frais_livraison' => $fraisLivraison,
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
            "A passé une commande de {$commande->montant_total} CFA (n°{$commande->id}).",
            commandeId: $commande->id,
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

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Commande n°{$commande->id} validée par le coordinateur.",
            commandeId: $commande->id,
        );

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

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Commande n°{$commande->id} marquée prête par le fournisseur.",
            commandeId: $commande->id,
        );

        return $this->success($commande->fresh('livraison'));
    }

    /**
     * Onglet "Suivi" (Espace Coordinateur) — timeline chronologique des
     * changements de statut de cette commande, avec l'acteur.
     */
    public function suivi(Request $request, Commande $commande): JsonResponse
    {
        $this->autoriserAcces($request, $commande);

        return $this->success(
            JournalAudit::where('commande_id', $commande->id)
                ->with('acteur:id,nom,prenom,type_utilisateur')
                ->orderBy('date_heure')
                ->get(['id', 'action', 'details', 'date_heure', 'acteur_id'])
        );
    }

    /**
     * Action rapide "transmettre à un livreur" (Espace Coordinateur) —
     * réassignation managériale, à la différence de LivraisonController::
     * affecter() (un livreur qui prend lui-même une livraison libre).
     */
    public function assignerLivreur(Request $request, Commande $commande): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_LIVRAISONS_ASSIGNER), 403);

        $data = $request->validate(['livreur_id' => ['required', 'exists:livreurs,user_id']]);

        $commande->loadMissing('livraison');
        abort_unless($commande->livraison, 422);

        // EN_LIVRAISON est inclus : un coordinateur peut réassigner à un autre
        // livreur une commande déjà en cours de livraison (panne du livreur
        // initial, erreur d'affectation...), pas seulement l'affecter une
        // première fois.
        if (! in_array($commande->statut_commande, [STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_EN_PREPARATION, STATUT_COMMANDE_EN_LIVRAISON], true)) {
            throw ValidationException::withMessages([
                'statut_commande' => ['Cette commande ne peut pas être transmise à un livreur dans son statut actuel.'],
            ]);
        }

        DB::transaction(function () use ($commande, $data) {
            $commande->livraison->update([
                'livreur_id' => $data['livreur_id'],
                'statut_livraison' => STATUT_LIVRAISON_EN_COURS,
                'date_prise_en_charge' => now(),
            ]);
            $commande->update(['statut_commande' => STATUT_COMMANDE_EN_LIVRAISON]);
        });

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Commande n°{$commande->id} transmise au livreur (user #{$data['livreur_id']}) par le coordinateur.",
            commandeId: $commande->id,
        );

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
     * Un commercial ou un coordinateur (qui relaie une vente WhatsApp/Facebook
     * via l'Espace Coordinateur) peut préciser le canal d'origine ; un client
     * qui commande lui-même passe toujours par la boutique de l'appli.
     */
    private function resoudreCanalVente(Request $request): int
    {
        if (in_array($request->user()->type_utilisateur, [ROLE_COMMERCIAL, ROLE_COORDINATEUR], true)
            && $request->filled('canal_vente_id')) {
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
     * client, si l'Admin a activé ce privilège dans le catalogue — neutralise
     * le frais de livraison calculé par calculerFraisLivraison() ci-dessous.
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

    /**
     * Frais de livraison : somme, une fois par ligne produit physique (pas
     * multiplié par la quantité — coût forfaitaire par produit/expédition),
     * du tarif défini par le fournisseur/admin pour la localité de l'adresse
     * choisie. Aucun tarif défini pour une localité → commande bloquée
     * (décision PDG : pas de repli silencieux à 0).
     *
     * @param  array<int, array{produit_id: int, quantite: int, prix_unitaire: mixed}>  $lignesAPersister
     * @param  \Illuminate\Support\Collection<int, Produit>  $produits
     */
    private function calculerFraisLivraison(Adresse $adresse, array $lignesAPersister, $produits, bool $livraisonGratuite): float
    {
        if ($livraisonGratuite) {
            return 0.0;
        }

        if ($adresse->localite_id === null) {
            throw ValidationException::withMessages([
                'adresse_id' => ["Cette adresse ne précise pas de localité reconnue — ajoutez une nouvelle adresse avec une localité pour commander un produit à livraison physique."],
            ]);
        }

        $total = 0.0;

        foreach ($lignesAPersister as $ligne) {
            $produit = $produits->get($ligne['produit_id']);

            if ($produit->estNumerique()) {
                continue;
            }

            $frais = FraisLivraisonProduit::where('produit_id', $produit->id)
                ->where('localite_id', $adresse->localite_id)
                ->value('montant');

            if ($frais === null) {
                throw ValidationException::withMessages([
                    'lignes' => ["Aucun frais de livraison n'est défini pour « {$produit->nom_produit} » vers « {$adresse->localite->nom} »."],
                ]);
            }

            $total += (float) $frais;
        }

        return $total;
    }

    private function autoriserAcces(Request $request, Commande $commande): void
    {
        abort_unless($commande->estAccessiblePar($request->user()), 403);
    }
}
