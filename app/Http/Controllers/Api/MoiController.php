<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Adresse;
use App\Models\JournalAudit;
use App\Models\LigneCommande;
use App\Models\NotificationOrdispace;
use App\Models\Produit;
use App\Models\User;
use App\Models\UtilisationPrivilege;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        return $this->success($data);
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
