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
        }

        return $this->success($query->latest('date_debut')->paginate(paginate_per_page($request)));
    }

    /**
     * Active un plan GarantiX sur un ordinateur précis déjà acheté — achat
     * simple et immédiat (paiement autodéclaré, comme le reste du circuit
     * de paiement actuel), valable un an, renouvelé manuellement à échéance.
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

        $dejaActif = AbonnementGarantix::where('ligne_commande_id', $ligne->id)
            ->where('statut', STATUT_ABONNEMENT_GARANTIX_ACTIF)
            ->where('date_fin', '>=', now())
            ->exists();

        if ($dejaActif) {
            throw ValidationException::withMessages([
                'ligne_commande_id' => ['Un abonnement GarantiX est déjà actif sur cet ordinateur.'],
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
            'statut_paiement' => STATUT_PAIEMENT_CONFIRME,
            'date_paiement' => now(),
        ]);

        JournalAudit::enregistrer(
            $user->id,
            ACTION_GARANTIX_SOUSCRIPTION,
            'abonnement_garantix',
            "S'est abonné à la formule « {$formule->libelle_complet} »."
        );

        return $this->success($abonnement->load('formule.prestations'), status: 201);
    }
}
