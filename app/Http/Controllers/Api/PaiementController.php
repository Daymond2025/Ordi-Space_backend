<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\JournalAudit;
use App\Models\Paiement;
use App\Services\Wave\WaveCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaiementController extends Controller
{
    public function __construct(private readonly WaveCheckoutService $wave) {}

    /**
     * Deux cas : le livreur encaisse à la remise du colis (commande
     * physique), ou le client règle lui-même une commande 100% numérique
     * (logiciels) qui n'a jamais de livreur assigné.
     */
    public function encaisser(Request $request, Commande $commande): JsonResponse
    {
        $user = $request->user();
        $livreurAutorise = $commande->livraison && $commande->livraison->livreur_id === $user->id;
        $clientAutorise = ! $commande->livraison && $commande->client_id === $user->id;

        abort_unless($livreurAutorise || $clientAutorise, 403);

        if ($commande->paiement) {
            throw ValidationException::withMessages(['commande' => ['Cette commande est déjà réglée.']]);
        }

        $data = $request->validate([
            'mode_paiement' => ['required', Rule::in([MODE_PAIEMENT_MOBILE_MONEY, MODE_PAIEMENT_ESPECES])],
            'reference_transaction' => ['nullable', 'string', 'max:255'],
        ]);

        // Délai de reversement du cash à l'entreprise — uniquement quand un
        // livreur encaisse physiquement des espèces (mobile money ne transite
        // jamais par ses mains). Voir écran "Les Missions" (badge "Dépôt").
        $especesEncaisseesParLivreur = $livreurAutorise && $data['mode_paiement'] === MODE_PAIEMENT_ESPECES;

        $paiement = Paiement::create([
            ...$data,
            'commande_id' => $commande->id,
            'livreur_id' => $livreurAutorise ? $user->id : null,
            // Montant net : total des lignes moins la remise Privilège Space
            // éventuellement appliquée à la commande (cf. montantNet()).
            'montant' => $commande->montantNet(),
            'statut_paiement' => STATUT_PAIEMENT_CONFIRME,
            'date_paiement' => now(),
            'date_limite_depot' => $especesEncaisseesParLivreur ? now()->addHours(24) : null,
        ]);

        $commande->livraison?->marquerLivree();

        return $this->success($paiement, status: 201);
    }

    /**
     * Mobile Money via Wave — variante de encaisser() : le paiement va
     * directement sur le compte marchand OrdiSpace, jamais sur celui du
     * livreur. Le Paiement démarre en_attente ; seul le webhook Wave (voir
     * WaveWebhookController) le fait passer à confirme/echoue, jamais une
     * déclaration du livreur ou du client.
     */
    public function initierPaiementWave(Request $request, Commande $commande): JsonResponse
    {
        $user = $request->user();
        $livreurAutorise = $commande->livraison && $commande->livraison->livreur_id === $user->id;
        $clientAutorise = ! $commande->livraison && $commande->client_id === $user->id;

        abort_unless($livreurAutorise || $clientAutorise, 403);

        if ($commande->paiement) {
            throw ValidationException::withMessages(['commande' => ['Cette commande est déjà réglée.']]);
        }

        $session = $this->wave->creerSession($commande);

        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreurAutorise ? $user->id : null,
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY,
            'montant' => $commande->montantNet(),
            'statut_paiement' => STATUT_PAIEMENT_EN_ATTENTE,
            'wave_checkout_session_id' => $session['id'] ?? null,
            'wave_launch_url' => $session['wave_launch_url'] ?? null,
        ]);

        return $this->success([
            'paiement' => $paiement,
            'wave_launch_url' => $session['wave_launch_url'] ?? null,
        ], status: 201);
    }

    /**
     * Secours manuel : le client a payé via un des numéros marchands affichés
     * (plutôt que de scanner le QR), donc aucun webhook Wave ne viendra —
     * c'est le SEUL cas où une confirmation déclarée par le livreur est
     * acceptée pour un paiement Wave. Voir EcranModePaiement (bouton
     * "Confirmer le paiement" dans la section "Si le client n'arrive pas à
     * scanner"). Journalisé pour rester traçable, contrairement à une
     * confirmation webhook normale.
     */
    public function confirmerManuellement(Request $request, Paiement $paiement): JsonResponse
    {
        abort_unless($paiement->livreur_id === $request->user()->id, 403);
        abort_unless($paiement->mode_paiement === MODE_PAIEMENT_MOBILE_MONEY, 422);

        if ($paiement->statut_paiement !== STATUT_PAIEMENT_EN_ATTENTE) {
            throw ValidationException::withMessages(['paiement' => ['Ce paiement a déjà été traité.']]);
        }

        $paiement->update(['statut_paiement' => STATUT_PAIEMENT_CONFIRME, 'date_paiement' => now()]);
        $paiement->commande->livraison?->marquerLivree();

        JournalAudit::enregistrer(
            $paiement->commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Paiement Mobile Money de la commande n°{$paiement->commande_id} confirmé manuellement par le livreur (numéro de secours, pas de scan QR).",
            commandeId: $paiement->commande_id,
        );

        return $this->success($paiement->fresh());
    }

    public function show(Request $request, Commande $commande): JsonResponse
    {
        // PERMISSION_COMMANDES_CONSULTER (voir routes/api.php) est large —
        // client, commercial et livreur l'ont tous, pas seulement pour leurs
        // propres commandes. Sans ce contrôle, n'importe quel utilisateur
        // authentifié pouvait lire le paiement (montant, wave_launch_url...)
        // de n'importe quelle commande en devinant son id. Même logique que
        // CommandeController::autoriserAcces().
        abort_unless($commande->estAccessiblePar($request->user()), 403);
        abort_unless($commande->paiement, 404);

        return $this->success($commande->paiement);
    }

    /** Le livreur confirme avoir reversé à l'entreprise le cash COD encaissé. */
    public function deposer(Request $request, Paiement $paiement): JsonResponse
    {
        abort_unless($paiement->livreur_id === $request->user()->id, 403);

        $paiement->update(['date_depot' => now()]);

        return $this->success($paiement->fresh());
    }
}
