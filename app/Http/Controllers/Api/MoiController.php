<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Adresse;
use App\Models\JournalAudit;
use App\Models\LigneCommande;
use App\Models\Livraison;
use App\Models\NotificationOrdispace;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\User;
use App\Models\UtilisationPrivilege;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class MoiController extends Controller
{
    public function profil(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $user->toArray();

        if ($user->type_utilisateur === ROLE_CLIENT) {
            $data['code_parrainage'] = $user->client->code_parrainage;
            $data['solde_portefeuille'] = $user->client->solde_portefeuille;
        }

        if ($user->type_utilisateur === ROLE_LIVREUR) {
            $data['disponible'] = $user->livreur->disponible;
            $data['type_vehicule'] = $user->livreur->type_vehicule;
        }

        return $this->success($data);
    }

    /**
     * Bascule "En ligne / Hors ligne" (écran Space, app Livreur) — ce même
     * champ Livreur::disponible existe déjà côté Coordinateur (lecture seule
     * sur LivreurController::show()), mais aucun endpoint ne permettait
     * jusqu'ici au livreur de le modifier lui-même.
     */
    public function basculerDisponibilite(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        $data = $request->validate(['disponible' => ['required', 'boolean']]);

        $request->user()->livreur->update($data);

        return $this->success(['disponible' => $data['disponible']]);
    }

    /**
     * Solde et historique du portefeuille — alimenté pour l'instant
     * uniquement par les récompenses de parrainage ("Carte invitation").
     */
    public function portefeuille(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_CLIENT, 403);

        $client = $request->user()->client;

        return $this->success([
            'solde' => $client->solde_portefeuille,
            'code_parrainage' => $client->code_parrainage,
            'transactions' => $client->transactionsPortefeuille()
                ->latest('date_transaction')
                ->paginate(paginate_per_page($request)),
        ]);
    }

    /**
     * Modifie l'identité et, en option, le mot de passe. Le rôle
     * (type_utilisateur) et les permissions ne se changent jamais ici.
     */
    public function modifierProfil(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'nom' => ['sometimes', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'mot_de_passe_actuel' => ['required_with:nouveau_mot_de_passe', 'string'],
            'nouveau_mot_de_passe' => ['sometimes', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        // Le téléphone est l'identifiant de connexion du Client (OTP WhatsApp) :
        // toujours normalisé avant stockage, et son unicité vérifiée sur la
        // forme normalisée (la règle "unique" seule ne suffit pas, un même
        // numéro pouvant être saisi sous plusieurs formats).
        if (! empty($data['telephone'])) {
            $data['telephone'] = normaliser_telephone($data['telephone']);

            if (User::where('telephone', $data['telephone'])->where('id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'telephone' => ['Ce numéro est déjà utilisé par un autre compte.'],
                ]);
            }
        }

        if (isset($data['nouveau_mot_de_passe'])) {
            if (! Hash::check($data['mot_de_passe_actuel'], $user->password)) {
                throw ValidationException::withMessages([
                    'mot_de_passe_actuel' => ['Mot de passe actuel incorrect.'],
                ]);
            }

            $data['password'] = Hash::make($data['nouveau_mot_de_passe']);
        }

        $user->update(collect($data)
            ->only(['nom', 'prenom', 'telephone', 'email', 'password'])
            ->all());

        return $this->success($user->fresh());
    }

    /**
     * Photo de profil — même triptyque validation/stockage/accessor que
     * ReclamationController::ajouterPreuve(), commun à tous les rôles.
     * Remplace l'ancienne photo sur le disque plutôt que de l'accumuler.
     */
    public function modifierPhoto(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
        ]);

        $ancienneCheminBrut = $user->getRawOriginal('photo');

        $user->update([
            'photo' => $data['photo']->store(PHOTO_PROFIL_DOSSIER, IMAGE_PRODUIT_DISQUE),
        ]);

        if ($ancienneCheminBrut) {
            Storage::disk(IMAGE_PRODUIT_DISQUE)->delete($ancienneCheminBrut);
        }

        return $this->success($user->fresh());
    }

    /**
     * Type de véhicule du Livreur — seul champ de son profil "métier" qui
     * n'était éditable nulle part (contrairement à disponible, voir
     * basculerDisponibilite()).
     */
    public function modifierVehicule(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        $data = $request->validate([
            'type_vehicule' => ['required', 'string', Rule::in(TYPES_VEHICULE_LIVREUR)],
        ]);

        $request->user()->livreur->update($data);

        return $this->success(['type_vehicule' => $data['type_vehicule']]);
    }

    public function adresses(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_CLIENT, 403);

        return $this->success(Adresse::where('client_id', $request->user()->id)->get());
    }

    public function ajouterAdresse(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_CLIENT, 403);

        $data = $request->validate([
            'libelle' => ['nullable', 'string', 'max:50'],
            'rue' => ['required', 'string', 'max:150'],
            'ville' => ['required', 'string', 'max:100'],
            'pays' => ['required', 'string', 'max:100'],
            // Champ structuré utilisé pour le rapprochement tarifaire (frais
            // de livraison) — "ville" reste un champ d'affichage libre.
            'localite_id' => ['required', 'exists:localites,id'],
        ]);

        $adresse = Adresse::create([...$data, 'client_id' => $request->user()->id]);

        return $this->success($adresse, status: 201);
    }

    public function supprimerAdresse(Request $request, Adresse $adresse): JsonResponse
    {
        abort_unless($adresse->client_id === $request->user()->id, 403);

        $adresse->delete();

        return $this->success(['message' => 'Adresse supprimée.']);
    }

    /**
     * Historique des avantages (Privilège Space) déjà utilisés par le client
     * — onglet "Avantages déjà utilisés" de l'app mobile.
     */
    public function privilegesUtilises(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_CLIENT, 403);

        $utilisations = UtilisationPrivilege::where('client_id', $request->user()->id)
            ->with('privilege')
            ->latest('date_utilisation')
            ->paginate(paginate_per_page($request));

        return $this->success($utilisations);
    }

    /**
     * "Mes achats" : les ordinateurs effectivement livrés au client — on se
     * base sur la présence d'une garantie (générée uniquement à la livraison
     * d'un produit physique avec durée de garantie), ce qui évite de dupliquer
     * un critère métier déjà défini ailleurs (Garantie::genererPourCommande).
     */
    public function achats(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_CLIENT, 403);

        $lignes = LigneCommande::with(['produit.images', 'produit.categorie', 'commande', 'garantie'])
            ->whereHas('commande', fn ($q) => $q->where('client_id', $request->user()->id))
            ->whereHas('garantie')
            ->latest('id')
            ->paginate(paginate_per_page($request));

        return $this->success($lignes);
    }

    public function achat(Request $request, LigneCommande $ligne): JsonResponse
    {
        $ligne->load(['produit.images', 'produit.categorie', 'commande.paiement', 'garantie', 'abonnementsGarantix.formule']);

        abort_unless($ligne->commande->client_id === $request->user()->id, 403);
        abort_unless($ligne->garantie, 404);

        $accessoiresCompatibles = Produit::where('statut_produit', STATUT_PRODUIT_VALIDE)
            ->whereHas('categorie', fn ($q) => $q->where('nom_categorie', 'Accessoires'))
            ->with('images')
            ->limit(6)
            ->get();

        return $this->success([
            'ligne' => $ligne,
            'abonnement_garantix_actif' => $ligne->abonnementsGarantix->first(fn ($a) => $a->estActif()),
            'abonnement_garantix_en_attente' => $ligne->abonnementsGarantix->first(fn ($a) => $a->estEnAttente()),
            'accessoires_compatibles' => $accessoiresCompatibles,
        ]);
    }

    /**
     * "Mes activités" (écran Compte, Espace Coordinateur) — journal des
     * actions effectuées PAR l'utilisateur connecté (JournalAudit::acteur_id,
     * déjà rempli par défaut sur auth('sanctum')->id() côté
     * JournalAudit::enregistrer()), filtrable par la même période que
     * l'accueil Space (resoudre_periode(), partagée avec EspaceController).
     */
    public function activites(Request $request): JsonResponse
    {
        [$debut, $fin] = resoudre_periode($request);

        $activites = JournalAudit::where('acteur_id', $request->user()->id)
            ->when($debut && $fin, fn ($q) => $q->whereBetween('date_heure', [$debut, $fin]))
            ->latest('date_heure')
            ->paginate(paginate_per_page($request));

        return $this->success($activites);
    }

    /**
     * "Mes paiements" (app Livreur) — les gains PROPRES du livreur, c'est-à-
     * dire ses frais de livraison (Commande::frais_livraison) sur ses
     * missions livrées, pas l'encaissement client (Paiement::montant, une
     * notion différente : voir POST /paiements/{id}/deposer, le dépôt du
     * cash COD à l'entreprise, inchangé par cette méthode). Aucun retrait
     * n'existe dans l'app : quand le client paie par Mobile Money,
     * l'entreprise reverse son gain au livreur en dehors de l'app ; quand il
     * paie en espèces, le livreur prélève directement son gain sur le cash
     * encaissé. `solde_total` est donc un cumul qui ne décroît jamais ici.
     * `periode` (resoudre_periode(), même helper que activites()) filtre les
     * compteurs et la liste de missions — jamais `solde_total`.
     * `gains_non_deposes` reste la notion préexistante et séparée du cash COD
     * pas encore reversé à l'entreprise (voir POST /paiements/{id}/deposer) —
     * utilisée par l'écran Space ("X FCFA disponible"), pas par "Mes paiements".
     */
    public function paiements(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        [$debut, $fin] = resoudre_periode($request);

        $livraisons = Livraison::where('livreur_id', $request->user()->id);
        $livraisonsLivrees = (clone $livraisons)->where('statut_livraison', STATUT_LIVRAISON_LIVREE);
        $paiementsEncaisses = $request->user()->livreur->paiementsEncaisses()
            ->where('statut_paiement', STATUT_PAIEMENT_CONFIRME);

        return $this->success([
            'solde_total' => (clone $livraisonsLivrees)->with('commande')->get()->sum(fn (Livraison $l) => (float) $l->commande->frais_livraison),
            'gains_non_deposes' => (clone $paiementsEncaisses)->whereNull('date_depot')->sum('montant'),
            // Échéance la plus proche parmi les dépôts en attente — carte
            // "Espèce à reverser" (compte à rebours) sur "Mes paiements".
            'date_limite_depot_urgente' => (clone $paiementsEncaisses)->whereNull('date_depot')->min('date_limite_depot'),
            'missions_recues' => (clone $livraisons)
                ->where('statut_livraison', '!=', STATUT_LIVRAISON_EN_ATTENTE_LIVREUR)
                ->when($debut && $fin, fn ($q) => $q->whereBetween('date_prise_en_charge', [$debut, $fin]))
                ->count(),
            'missions_validees' => (clone $livraisonsLivrees)
                ->when($debut && $fin, fn ($q) => $q->whereBetween('date_livraison_effective', [$debut, $fin]))
                ->count(),
            'gains_periode' => (clone $livraisonsLivrees)
                ->when($debut && $fin, fn ($q) => $q->whereBetween('date_livraison_effective', [$debut, $fin]))
                ->with('commande')->get()->sum(fn (Livraison $l) => (float) $l->commande->frais_livraison),
            'missions' => (clone $livraisonsLivrees)
                ->when($debut && $fin, fn ($q) => $q->whereBetween('date_livraison_effective', [$debut, $fin]))
                ->with(['commande.lignes.produit.fournisseur', 'adresse.localite'])
                ->latest('date_livraison_effective')
                ->paginate(paginate_per_page($request)),
        ]);
    }

    /**
     * "Reverser" (carte "Espèce à reverser" sur "Mes paiements") — dépose en
     * une fois tous les paiements cash confirmés pas encore reversés à
     * l'entreprise (mêmes critères que gains_non_deposes ci-dessus), plutôt
     * que de répéter POST /paiements/{id}/deposer un par un : la carte
     * affiche un montant consolidé, l'action doit l'être aussi.
     */
    public function reverserPaiements(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        $paiements = $request->user()->livreur->paiementsEncaisses()
            ->where('statut_paiement', STATUT_PAIEMENT_CONFIRME)
            ->whereNull('date_depot')
            ->get();

        $paiements->each(fn (Paiement $p) => $p->update(['date_depot' => now()]));

        return $this->success(['nombre_reverse' => $paiements->count()]);
    }

    /**
     * Récapitulatif du jour affiché sur l'écran "Livraison terminée !" —
     * nombre de missions livrées aujourd'hui et somme des frais de livraison
     * gagnés par le livreur (Commande::frais_livraison, la rémunération du
     * livreur — pas Paiement::montant utilisé par paiements() ci-dessus, qui
     * est l'encaissement client et une notion distincte).
     */
    public function recapitulatifJour(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        $livraisonsAujourdhui = Livraison::where('livreur_id', $request->user()->id)
            ->where('statut_livraison', STATUT_LIVRAISON_LIVREE)
            ->whereDate('date_livraison_effective', today())
            ->with('commande')
            ->get();

        return $this->success([
            'livraisons_du_jour' => $livraisonsAujourdhui->count(),
            'revenu_du_jour' => $livraisonsAujourdhui->sum(fn (Livraison $l) => (float) $l->commande->frais_livraison),
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        return $this->success(
            NotificationOrdispace::where('user_id', $request->user()->id)
                ->latest('date_envoi')
                ->paginate(paginate_per_page($request))
        );
    }

    public function marquerNotificationLue(Request $request, NotificationOrdispace $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->update(['lu' => true]);

        return $this->success($notification);
    }
}
