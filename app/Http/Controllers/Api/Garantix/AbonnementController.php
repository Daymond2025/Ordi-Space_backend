<?php

namespace App\Http\Controllers\Api\Garantix;

use App\Http\Controllers\Controller;
use App\Models\AbonnementGarantix;
use App\Models\FormuleGarantix;
use App\Models\JournalAudit;
use App\Models\LigneCommande;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AbonnementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = AbonnementGarantix::with(['formule', 'ligneCommande.produit']);

        if ($user->type_utilisateur === ROLE_CLIENT) {
            $query->where('client_id', $user->id);
        } else {
            $query->with('client.user');
        }

        if ($request->filled('statut_paiement')) {
            $query->where('statut_paiement', $request->query('statut_paiement'));
        }

        return $this->success($query->latest('date_debut')->paginate(paginate_per_page($request)));
    }

    /**
     * Demande d'activation d'un plan GarantiX sur un ordinateur précis déjà
     * acheté. Aucun paiement en ligne n'existe dans l'app : le client ne fait
     * que déclarer son intention de payer en espèces/Mobile Money auprès de
     * l'équipe. L'abonnement est créé en attente — il ne procure aucun
     * bénéfice (cf. AbonnementGarantix::estActif()) tant qu'un Admin n'a pas
     * confirmé la réception du paiement via confirmerPaiement().
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->type_utilisateur === ROLE_CLIENT, 403);

        $data = $request->validate([
            'ligne_commande_id' => ['required', 'exists:lignes_commande,id'],
            'formule_garantix_id' => ['required', 'exists:formules_garantix,id'],
            'mode_paiement' => ['required', Rule::in([MODE_PAIEMENT_MOBILE_MONEY, MODE_PAIEMENT_ESPECES])],
            'reference_transaction' => ['nullable', 'string', 'max:255'],
        ]);

        $ligne = LigneCommande::with('produit', 'commande')->findOrFail($data['ligne_commande_id']);
        $formule = FormuleGarantix::findOrFail($data['formule_garantix_id']);

        abort_unless($ligne->commande->client_id === $user->id, 403, 'Cet achat ne vous appartient pas.');

        if (! $ligne->produit || $ligne->produit->estNumerique()) {
            throw ValidationException::withMessages([
                'ligne_commande_id' => ['GarantiX ne couvre que les ordinateurs, pas les licences numériques.'],
            ]);
        }

        if (! $formule->actif) {
            throw ValidationException::withMessages([
                'formule_garantix_id' => ['Cette formule GarantiX n\'est plus proposée.'],
            ]);
        }

        $dejaActifOuEnAttente = AbonnementGarantix::where('ligne_commande_id', $ligne->id)
            ->where('date_fin', '>=', now())
            ->whereIn('statut_paiement', [STATUT_PAIEMENT_EN_ATTENTE, STATUT_PAIEMENT_CONFIRME])
            ->where('statut', STATUT_ABONNEMENT_GARANTIX_ACTIF)
            ->exists();

        if ($dejaActifOuEnAttente) {
            throw ValidationException::withMessages([
                'ligne_commande_id' => ['Un abonnement GarantiX est déjà actif ou en attente de confirmation sur cet ordinateur.'],
            ]);
        }

        $abonnement = AbonnementGarantix::create([
            'client_id' => $user->id,
            'ligne_commande_id' => $ligne->id,
            'formule_garantix_id' => $formule->id,
            'date_debut' => now(),
            'date_fin' => now()->addYear(),
            'statut' => STATUT_ABONNEMENT_GARANTIX_ACTIF,
            'mode_paiement' => $data['mode_paiement'],
            'reference_transaction' => $data['reference_transaction'] ?? null,
            'statut_paiement' => STATUT_PAIEMENT_EN_ATTENTE,
            'date_paiement' => null,
        ]);

        JournalAudit::enregistrer(
            $user->id,
            ACTION_GARANTIX_SOUSCRIPTION,
            'abonnement_garantix',
            "A demandé l'activation de la formule « {$formule->libelle_complet} » (paiement à confirmer)."
        );

        return $this->success($abonnement->load('formule.prestations'), status: 201);
    }

    /**
     * L'Admin confirme avoir reçu le paiement (espèces/Mobile Money) déclaré
     * par le client — l'abonnement devient réellement actif à partir de là.
     */
    public function confirmerPaiement(Request $request, AbonnementGarantix $abonnement): JsonResponse
    {
        if ($abonnement->statut_paiement !== STATUT_PAIEMENT_EN_ATTENTE) {
            throw ValidationException::withMessages([
                'statut_paiement' => ['Cette demande n\'est plus en attente de confirmation.'],
            ]);
        }

        $abonnement->update([
            'statut_paiement' => STATUT_PAIEMENT_CONFIRME,
            'date_paiement' => now(),
        ]);

        JournalAudit::enregistrer(
            $request->user()->id,
            ACTION_GARANTIX_SOUSCRIPTION,
            'abonnement_garantix',
            "A confirmé le paiement de l'abonnement GarantiX #{$abonnement->id}."
        );

        return $this->success($abonnement->load('formule.prestations'));
    }

    /**
     * L'Admin rejette une demande d'activation (le client n'a en fait pas
     * payé) — l'abonnement n'accordera jamais de bénéfice.
     */
    public function rejeterPaiement(Request $request, AbonnementGarantix $abonnement): JsonResponse
    {
        if ($abonnement->statut_paiement !== STATUT_PAIEMENT_EN_ATTENTE) {
            throw ValidationException::withMessages([
                'statut_paiement' => ['Cette demande n\'est plus en attente de confirmation.'],
            ]);
        }

        $abonnement->update(['statut_paiement' => STATUT_PAIEMENT_ECHOUE]);

        JournalAudit::enregistrer(
            $request->user()->id,
            ACTION_GARANTIX_SOUSCRIPTION,
            'abonnement_garantix',
            "A rejeté la demande d'activation de l'abonnement GarantiX #{$abonnement->id}."
        );

        return $this->success($abonnement->load('formule.prestations'));
    }
}
