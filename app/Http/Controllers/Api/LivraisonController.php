<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalAudit;
use App\Models\Livraison;
use App\Models\NotificationOrdispace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LivraisonController extends Controller
{
    private const RELATIONS_MISSION = [
        'commande.lignes.produit.images', 'commande.lignes.produit.fournisseur.user',
        'commande.client.user', 'commande.paiement', 'adresse.localite',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Livraison::with(self::RELATIONS_MISSION);

        match ($user->type_utilisateur) {
            // Un livreur voit ses courses + le vivier des livraisons non affectées.
            ROLE_LIVREUR => $query->where(fn ($q) => $q
                ->where('livreur_id', $user->id)
                ->orWhere('statut_livraison', STATUT_LIVRAISON_EN_ATTENTE_LIVREUR)),
            default => null,
        };

        $missions = $query->latest('id')->paginate(paginate_per_page($request));
        $missions->getCollection()->transform(fn (Livraison $livraison) => $this->formaterMission($livraison));

        return $this->success($missions);
    }

    public function show(Request $request, Livraison $livraison): JsonResponse
    {
        $user = $request->user();
        $autorise = match ($user->type_utilisateur) {
            ROLE_CLIENT => $livraison->commande->client_id === $user->id,
            ROLE_LIVREUR => in_array($livraison->livreur_id, [null, $user->id], true),
            default => true, // coordinateur, administrateur
        };

        abort_unless($autorise, 403);

        $livraison->load(self::RELATIONS_MISSION);

        return $this->success($this->formaterMission($livraison));
    }

    /**
     * Forme enrichie d'une mission pour l'app Livreur — même esprit que
     * LivreurController::missions() (vue coordinateur), avec en plus la photo
     * produit, le contact client et le lien Google Maps de destination
     * (Adresse::lien_maps). Pas de lien maps fournisseur pour l'instant : ce
     * champ n'existe que côté Fournisseur et attend que l'app Fournisseur
     * permette de le renseigner.
     */
    private function formaterMission(Livraison $livraison): array
    {
        $commande = $livraison->commande;
        $paiement = $commande->paiement;
        $produit = $commande->lignes->first()?->produit;
        $client = $commande->client;
        $fournisseur = $produit?->fournisseur;

        return [
            'id' => $livraison->id,
            'commande_id' => $livraison->commande_id,
            'nom_produit' => $produit?->nom_produit,
            'photo' => $produit?->images->first()?->url_image,
            'nom_client' => trim(($client?->user?->prenom ?? '').' '.($client?->user?->nom ?? '')),
            'telephone_client' => $client?->user?->telephone,
            'nom_fournisseur' => $fournisseur?->nom_entreprise,
            'zone_fournisseur' => $fournisseur?->adresse_entreprise,
            'telephone_fournisseur' => $fournisseur?->telephone_gerant ?? $fournisseur?->user?->telephone,
            'zone_destination' => $livraison->adresse->localite->nom ?? null,
            'lien_maps_destination' => $livraison->adresse->lien_maps,
            'statut_commande' => $commande->statut_commande,
            'statut_livraison' => $livraison->statut_livraison,
            'colis_recupere_le' => $livraison->colis_recupere_le,
            'livraison_demarree_le' => $livraison->livraison_demarree_le,
            'arrivee_le' => $livraison->arrivee_le,
            'date_prise_en_charge' => $livraison->date_prise_en_charge,
            'retour_necessaire' => $livraison->retour_necessaire,
            'statut_retour' => $livraison->statut_retour,
            'frais_livraison' => $commande->frais_livraison,
            'montant_produit' => (float) $commande->montant_total,
            'nombre_colis' => (int) $commande->lignes->sum('quantite'),
            // Le reliquat du client (total moins la confirmation déjà payée en ligne) : ce que le livreur encaisse.
            'montant_total_a_payer' => $commande->reliquat(),
            'acompte_confirmation_paye' => $commande->acomptePaye(),
            'preuve_livraison' => $livraison->preuve_livraison,
            'date_livraison_prevue' => $livraison->date_livraison_prevue,
            'date_livraison_effective' => $livraison->date_livraison_effective,
            'paiement' => $paiement ? [
                'id' => $paiement->id,
                'mode_paiement' => $paiement->mode_paiement,
                'statut_paiement' => $paiement->statut_paiement,
                'date_limite_depot' => $paiement->date_limite_depot,
                'date_depot' => $paiement->date_depot,
            ] : null,
        ];
    }

    public function affecter(Request $request, Livraison $livraison): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        // livreur_id est déjà null dès la création de la livraison
        // (statut_livraison "en_preparation", voir CommandeController::store())
        // — vérifier ce seul champ laisserait n'importe quel livreur s'emparer
        // d'une commande pas encore publiée au vivier par le coordinateur.
        if ($livraison->statut_livraison !== STATUT_LIVRAISON_EN_ATTENTE_LIVREUR) {
            throw ValidationException::withMessages(['livraison' => ["Cette livraison n'est pas disponible dans le vivier."]]);
        }

        if ($livraison->livreur_id !== null) {
            throw ValidationException::withMessages(['livraison' => ['Cette livraison est déjà prise en charge.']]);
        }

        $livraison->update([
            'livreur_id' => $request->user()->id,
            'statut_livraison' => STATUT_LIVRAISON_EN_COURS,
            'date_prise_en_charge' => now(),
        ]);

        $livraison->commande()->update(['statut_commande' => STATUT_COMMANDE_EN_LIVRAISON]);

        JournalAudit::enregistrer(
            $livraison->commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Livraison de la commande n°{$livraison->commande_id} prise en charge par le livreur.",
            commandeId: $livraison->commande_id,
        );

        NotificationOrdispace::create([
            'user_id' => $request->user()->id,
            'type_notification' => 'mission_acceptee',
            'titre' => 'Mission acceptée',
            'contenu' => "Vous avez pris en charge la livraison de la commande n°{$livraison->commande_id}.",
            'lu' => false,
            'date_envoi' => now(),
        ]);

        return $this->success($livraison->fresh());
    }

    /**
     * Acceptation d'une mission assignée par le coordinateur (statut
     * 'assignee' — voir CommandeController::assignerLivreur()) — distinct de
     * affecter() ci-dessus, qui reste une auto-prise en charge immédiate
     * depuis le vivier, sans étape d'acceptation.
     */
    public function accepter(Request $request, Livraison $livraison): JsonResponse
    {
        abort_unless($livraison->livreur_id === $request->user()->id, 403);

        if ($livraison->statut_livraison !== STATUT_LIVRAISON_ASSIGNEE) {
            throw ValidationException::withMessages(['livraison' => ["Cette livraison n'est pas en attente d'acceptation."]]);
        }

        $livraison->update([
            'statut_livraison' => STATUT_LIVRAISON_EN_COURS,
            'date_prise_en_charge' => now(),
        ]);

        JournalAudit::enregistrer(
            $livraison->commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Mission de la commande n°{$livraison->commande_id} acceptée par le livreur.",
            commandeId: $livraison->commande_id,
        );

        NotificationOrdispace::create([
            'user_id' => $request->user()->id,
            'type_notification' => 'mission_acceptee',
            'titre' => 'Mission acceptée',
            'contenu' => "Vous avez accepté la mission de livraison de la commande n°{$livraison->commande_id}.",
            'lu' => false,
            'date_envoi' => now(),
        ]);

        return $this->success($livraison->fresh());
    }

    /**
     * Le livreur confirme avoir récupéré le colis chez le fournisseur — étape
     * distincte du statut 'en_cours' (qui démarre dès l'acceptation/prise en
     * charge, avant même le passage chez le fournisseur).
     */
    public function recuperer(Request $request, Livraison $livraison): JsonResponse
    {
        abort_unless($livraison->livreur_id === $request->user()->id, 403);

        if ($livraison->statut_livraison !== STATUT_LIVRAISON_EN_COURS) {
            throw ValidationException::withMessages(['livraison' => ["Cette livraison n'est pas en cours."]]);
        }

        $livraison->update(['colis_recupere_le' => now()]);

        return $this->success($livraison->fresh());
    }

    /**
     * Le livreur confirme démarrer le trajet vers le client — étape purement
     * déclarative (le colis est déjà récupéré) qui distingue "En route pour
     * livraison" de "Livraison en cours" sur l'écran Space, et donne accès à
     * l'écran de suivi vers le client.
     */
    public function demarrerLivraison(Request $request, Livraison $livraison): JsonResponse
    {
        abort_unless($livraison->livreur_id === $request->user()->id, 403);

        if ($livraison->statut_livraison !== STATUT_LIVRAISON_EN_COURS || ! $livraison->colis_recupere_le) {
            throw ValidationException::withMessages(['livraison' => ["Le colis n'a pas encore été récupéré."]]);
        }

        $livraison->update(['livraison_demarree_le' => now()]);

        return $this->success($livraison->fresh());
    }

    /**
     * Le livreur confirme être arrivé chez le client — persiste ce qui était
     * jusqu'ici un simple état local côté frontend (perdu si l'écran était
     * quitté puis rouvert), donne accès à l'écran "Comment paye le client ?".
     */
    public function arriver(Request $request, Livraison $livraison): JsonResponse
    {
        abort_unless($livraison->livreur_id === $request->user()->id, 403);

        if ($livraison->statut_livraison !== STATUT_LIVRAISON_EN_COURS || ! $livraison->livraison_demarree_le) {
            throw ValidationException::withMessages(['livraison' => ["La livraison vers le client n'a pas encore démarré."]]);
        }

        $livraison->update(['arrivee_le' => now()]);

        return $this->success($livraison->fresh());
    }

    public function livrer(Request $request, Livraison $livraison): JsonResponse
    {
        abort_unless($livraison->livreur_id === $request->user()->id, 403);

        $data = $request->validate([
            'preuve_livraison' => ['required', 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
        ]);

        $livraison->update(['preuve_livraison' => $data['preuve_livraison']->store(LIVRAISON_PREUVE_DOSSIER, IMAGE_PRODUIT_DISQUE)]);
        $livraison->marquerLivree();

        return $this->success($livraison->fresh());
    }

    /**
     * Le livreur annule la commande depuis "Comment paye le client ?" (client
     * injoignable, produit refusé, etc.) — réutilise le même mécanisme que le
     * Coordinateur (Commande::appliquerChangementStatut(), voir aussi
     * CommandeController::changerStatut()) : restock, commande "annulee",
     * et livraison marquée "echouee" + retour_necessaire (le colis est déjà
     * en main du livreur, il doit le rapporter au fournisseur).
     */
    public function annuler(Request $request, Livraison $livraison): JsonResponse
    {
        abort_unless($livraison->livreur_id === $request->user()->id, 403);

        if ($livraison->statut_livraison !== STATUT_LIVRAISON_EN_COURS) {
            throw ValidationException::withMessages(['livraison' => ["Cette livraison n'est pas en cours."]]);
        }

        $data = $request->validate(['motif' => ['required', 'string', 'max:255']]);

        $livraison->commande->appliquerChangementStatut(STATUT_COMMANDE_ANNULEE);

        JournalAudit::enregistrer(
            $livraison->commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Commande n°{$livraison->commande_id} annulée par le livreur — motif : {$data['motif']}.",
            commandeId: $livraison->commande_id,
        );

        return $this->success($livraison->fresh());
    }

    /**
     * Le livreur confirme avoir rapporté le colis au fournisseur après une
     * annulation (bouton "Colis Retourné") — seul point d'écriture de
     * STATUT_RETOUR_LIVRAISON_EFFECTUE, jusqu'ici jamais posé nulle part.
     */
    public function retourner(Request $request, Livraison $livraison): JsonResponse
    {
        abort_unless($livraison->livreur_id === $request->user()->id, 403);

        if (! $livraison->retour_necessaire || $livraison->statut_retour !== STATUT_RETOUR_LIVRAISON_EN_COURS) {
            throw ValidationException::withMessages(['livraison' => ["Aucun retour en cours pour cette livraison."]]);
        }

        $livraison->update(['statut_retour' => STATUT_RETOUR_LIVRAISON_EFFECTUE]);

        return $this->success($livraison->fresh());
    }
}
