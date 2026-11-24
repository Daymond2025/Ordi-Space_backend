<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Commercial;
use App\Models\JournalAudit;
use App\Models\User;
use App\Services\CommissionCommercial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Écran "Les commerciaux" (Espace Agent, bottombar) — Espace Coordinateur.
 */
class CommercialController extends Controller
{
    /**
     * Statistiques calculées par sous-requête corrélée (même technique que
     * FournisseurController::index()) — pas de colonnes dédiées.
     * "commission_totale" = commissions des commandes livrées, par produit
     * vendu selon le type du commercial (voir App\Services\CommissionCommercial).
     */
    public function liste(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_CONSULTER), 403);

        $commandesCommercial = fn () => Commande::selectRaw('count(*)')->whereColumn('commercial_id', 'commerciaux.user_id');

        $commerciaux = Commercial::with('user')
            ->where('type_commercial', TYPE_COMMERCIAL_HUMAIN)
            ->when(
                $request->user()->type_utilisateur !== ROLE_ADMINISTRATEUR,
                fn ($q) => $q->whereHas('user', fn ($u) => $u->where('statut_compte', '!=', STATUT_COMPTE_SUPPRIME)),
            )
            ->addSelect([
                'commandes_total_count' => $commandesCommercial(),
                'commandes_validees_count' => $commandesCommercial()->where('commandes.statut_commande', STATUT_COMMANDE_VALIDEE),
                'commandes_annulees_count' => $commandesCommercial()->where('commandes.statut_commande', STATUT_COMMANDE_ANNULEE),
                'commandes_livrees_count' => $commandesCommercial()->where('commandes.statut_commande', STATUT_COMMANDE_LIVREE),
            ])
            ->withCasts([
                'commandes_total_count' => 'integer',
                'commandes_validees_count' => 'integer',
                'commandes_annulees_count' => 'integer',
                'commandes_livrees_count' => 'integer',
            ])
            ->get();

        $commissions = CommissionCommercial::totauxLivreesParCommercial($commerciaux->pluck('user_id'));

        $commerciaux = $commerciaux
            ->map(fn (Commercial $commercial) => [
                'user_id' => $commercial->user_id,
                'nom' => $commercial->user->nom,
                'prenom' => $commercial->user->prenom,
                'actif' => $commercial->user->statut_compte === STATUT_COMPTE_ACTIF,
                'categorie_commercial' => $commercial->categorie_commercial,
                'membre_depuis' => $commercial->created_at,
                'commandes_total' => $commercial->commandes_total_count,
                'commandes_validees' => $commercial->commandes_validees_count,
                'commandes_annulees' => $commercial->commandes_annulees_count,
                'commission_totale' => (float) ($commissions[$commercial->user_id] ?? 0),
            ]);

        return $this->success($commerciaux);
    }

    /**
     * Écran "Profil commercial" — ouvert au tap sur l'avatar d'une carte de
     * l'écran "Les commerciaux". Un seul commercial : pas besoin de la
     * sous-requête corrélée de liste(), des comptages directs suffisent.
     */
    public function show(Request $request, Commercial $commercial): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_CONSULTER), 403);

        $commandes = $commercial->commandes();

        return $this->success([
            'user_id' => $commercial->user_id,
            'nom' => $commercial->user->nom,
            'prenom' => $commercial->user->prenom,
            'telephone' => $commercial->user->telephone,
            'email' => $commercial->user->email,
            'photo' => $commercial->user->photo,
            'statut_compte' => $commercial->user->statut_compte,
            'actif' => $commercial->user->statut_compte === STATUT_COMPTE_ACTIF,
            'nom_entreprise' => $commercial->nom_entreprise,
            'localisation' => $commercial->localisation,
            'categorie_commercial' => $commercial->categorie_commercial,
            'statistiques' => [
                'commandes_total' => (clone $commandes)->count(),
                'commandes_validees' => (clone $commandes)->where('statut_commande', STATUT_COMMANDE_VALIDEE)->count(),
                'commandes_annulees' => (clone $commandes)->where('statut_commande', STATUT_COMMANDE_ANNULEE)->count(),
                'commission_totale' => CommissionCommercial::totalLivrees($commercial->commandes()),
            ],
        ]);
    }

    /**
     * Historique des commandes saisies par CE commercial — fiche Admin/
     * Coordinateur ("aucun détail ne doit échapper à l'admin"), les stats de
     * show() ne donnant que des compteurs, jamais la liste elle-même.
     */
    public function commandes(Request $request, Commercial $commercial): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_CONSULTER), 403);

        $commandes = $commercial->commandes()
            ->with(['client.user', 'lignes.produit'])
            ->latest('date_commande')
            ->paginate(paginate_per_page($request))
            ->through(fn (Commande $commande) => [
                'commande_id' => $commande->id,
                'nom_produit' => $commande->lignes->first()?->produit?->nom_produit,
                'nom_client' => trim(($commande->client?->user?->prenom ?? '').' '.($commande->client?->user?->nom ?? '')),
                'statut' => $commande->statut_commande,
                'montant_total' => (float) $commande->montant_total,
                'date_commande' => $commande->date_commande,
            ]);

        return $this->success($commandes);
    }

    /**
     * Fiche "métier" (entreprise/localisation) — réservée Admin, jamais
     * Coordinateur (même principe que LivreurController::modifierProfil()) :
     * CRUD complet côté admin, comme pour un livreur.
     */
    public function modifierProfilAdmin(Request $request, Commercial $commercial): JsonResponse
    {
        $data = $request->validate([
            'nom_entreprise' => ['nullable', 'string', 'max:150'],
            'localisation' => ['nullable', 'string', 'max:255'],
            // Le changement ne vaut que pour les commissions à venir : celles
            // déjà créditées restent figées sur leur commande.
            'categorie_commercial' => ['sometimes', Rule::in(CATEGORIES_COMMERCIAL)],
        ]);

        $commercial->update($data);

        return $this->success($commercial->fresh());
    }

    /**
     * Active/suspend le compte du commercial — action de gestion d'équipe du
     * coordinateur, à la différence du toggle "En ligne" du livreur (lecture
     * seule, reflète un statut que seul le livreur contrôle).
     */
    public function changerStatut(Request $request, Commercial $commercial): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_GERER), 403);

        $data = $request->validate(['actif' => ['required', 'boolean']]);

        abort_if($commercial->user->statut_compte === STATUT_COMPTE_SUPPRIME, 422, 'Ce compte est supprimé.');

        $commercial->user->update([
            'statut_compte' => $data['actif'] ? STATUT_COMPTE_ACTIF : STATUT_COMPTE_SUSPENDU,
        ]);

        // Suspendu : le commercial est déconnecté de tous ses appareils.
        if (! $data['actif']) {
            $commercial->user->tokens()->delete();
        }

        JournalAudit::enregistrer(
            $commercial->user_id,
            ACTION_COMMERCIAL_STATUT,
            'commercial',
            ($data['actif'] ? 'A réactivé' : 'A suspendu').' le commercial '.$this->nomComplet($commercial->user).'.',
        );

        return $this->success(['actif' => $data['actif']]);
    }

    /**
     * Création d'un commercial par le coordinateur (écran Agent) — même
     * principe que l'Admin (UtilisateurController::provisionner()) : compte
     * interne créé avec son numéro WhatsApp, connexion par code, sans mot de
     * passe. Un compte supprimé du même numéro libère ce numéro.
     */
    public function creer(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_GERER), 403);

        if ($request->filled('telephone')) {
            User::libererIdentifiantsSupprimes(ROLE_COMMERCIAL, null, normaliser_telephone((string) $request->input('telephone')));
        }

        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'telephone' => ['required', 'string', 'max:30'],
            'categorie_commercial' => ['required', Rule::in(CATEGORIES_COMMERCIAL)],
            'nom_entreprise' => ['nullable', 'string', 'max:150'],
            'localisation' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
        ]);

        $telephone = normaliser_telephone($data['telephone']);
        $this->verifierTelephoneLibre($telephone);

        $photo = $request->hasFile('photo') ? $request->file('photo')->store(PHOTO_PROFIL_DOSSIER, IMAGE_PRODUIT_DISQUE) : null;

        $user = DB::transaction(function () use ($data, $telephone, $photo) {
            $user = User::create([
                'nom' => $data['nom'],
                'prenom' => $data['prenom'] ?? null,
                'telephone' => $telephone,
                // Jamais communiqué : connexion par numéro + code WhatsApp.
                'password' => Hash::make(Str::random(40)),
                'type_utilisateur' => ROLE_COMMERCIAL,
                'statut_compte' => STATUT_COMPTE_ACTIF,
                'photo' => $photo,
            ]);

            Commercial::create([
                'user_id' => $user->id,
                'type_commercial' => TYPE_COMMERCIAL_HUMAIN,
                'categorie_commercial' => $data['categorie_commercial'],
                'nom_entreprise' => $data['nom_entreprise'] ?? null,
                'localisation' => $data['localisation'] ?? null,
            ]);

            $user->assignRole(ROLE_COMMERCIAL);

            return $user;
        });

        JournalAudit::enregistrer($user->id, ACTION_COMMERCIAL_CREE, 'commercial', 'A ajouté le commercial '.$this->nomComplet($user).'.');

        return $this->success(['user_id' => $user->id], status: 201);
    }

    /** Modification de l'identité et de la fiche métier d'un commercial (coordinateur). */
    public function modifier(Request $request, Commercial $commercial): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_GERER), 403);
        abort_if($commercial->type_commercial !== TYPE_COMMERCIAL_HUMAIN, 404);
        abort_if($commercial->user->statut_compte === STATUT_COMPTE_SUPPRIME, 422, 'Ce compte est supprimé.');

        $data = $request->validate([
            'nom' => ['sometimes', 'required', 'string', 'max:100'],
            'prenom' => ['sometimes', 'nullable', 'string', 'max:100'],
            'telephone' => ['sometimes', 'required', 'string', 'max:30'],
            'categorie_commercial' => ['sometimes', Rule::in(CATEGORIES_COMMERCIAL)],
            'nom_entreprise' => ['sometimes', 'nullable', 'string', 'max:150'],
            'localisation' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (isset($data['telephone'])) {
            $data['telephone'] = normaliser_telephone($data['telephone']);
            User::libererIdentifiantsSupprimes(ROLE_COMMERCIAL, null, $data['telephone']);
            $this->verifierTelephoneLibre($data['telephone'], $commercial->user_id);
        }

        DB::transaction(function () use ($commercial, $data) {
            $commercial->user->update(array_intersect_key($data, array_flip(['nom', 'prenom', 'telephone'])));
            // Catégorie : ne vaut que pour les commissions à venir.
            $commercial->update(array_intersect_key($data, array_flip(['categorie_commercial', 'nom_entreprise', 'localisation'])));
        });

        JournalAudit::enregistrer($commercial->user_id, ACTION_COMMERCIAL_MODIFIE, 'commercial', 'A modifié la fiche du commercial '.$this->nomComplet($commercial->user->fresh()).'.');

        return $this->success(['user_id' => $commercial->user_id]);
    }

    /**
     * Suppression par le coordinateur — même règle que l'Admin : le compte
     * passe au statut « supprimé » (définitif), ses commandes et commissions
     * restent intactes, ses sessions sont fermées, son numéro est libéré.
     */
    public function supprimer(Request $request, Commercial $commercial): JsonResponse
    {
        abort_unless($request->user()->can(PERMISSION_COMMERCIAUX_GERER), 403);
        abort_if($commercial->type_commercial !== TYPE_COMMERCIAL_HUMAIN, 404);

        $user = $commercial->user;
        if ($user->statut_compte !== STATUT_COMPTE_SUPPRIME) {
            $user->update(['statut_compte' => STATUT_COMPTE_SUPPRIME]);
            $user->tokens()->delete();
            JournalAudit::enregistrer($user->id, ACTION_COMMERCIAL_SUPPRIME, 'commercial', 'A supprimé le commercial '.$this->nomComplet($user).'.');
        }

        return $this->success(['supprime' => true]);
    }

    private function verifierTelephoneLibre(string $telephone, ?int $saufUserId = null): void
    {
        $pris = User::where('type_utilisateur', ROLE_COMMERCIAL)
            ->where('telephone', $telephone)
            ->when($saufUserId, fn ($q) => $q->where('id', '!=', $saufUserId))
            ->exists();

        if ($pris) {
            throw ValidationException::withMessages(['telephone' => ['Ce numéro est déjà utilisé par un autre commercial.']]);
        }
    }

    private function nomComplet(User $user): string
    {
        return trim(($user->prenom ?? '').' '.$user->nom);
    }
}
