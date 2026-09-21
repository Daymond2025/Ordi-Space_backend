<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DemandeRetrait;
use App\Models\Livreur;
use App\Models\VenteBoutique;
use App\Services\PortefeuilleCommissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Portefeuille de commissions du livreur (écran "Portefeuille" de la Boutique) :
 * ses soldes, la liste de ses commissions et ses demandes de retrait. Le
 * traitement des demandes par l'Admin est dans Admin\RetraitController.
 */
class PortefeuilleController extends Controller
{
    /** Soldes : disponible, gain total cumulé, en attente de validation… (voir PortefeuilleCommissions). */
    public function resume(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        return $this->success([
            ...PortefeuilleCommissions::resume($request->user()),
            'retrait_minimum' => RETRAIT_MONTANT_MINIMUM,
        ]);
    }

    /**
     * Onglet "Mes commissions" : une ligne par vente, plus récente d'abord.
     * `statut` : "en_attente" (commande pas encore validée — commission pas
     * encore acquise), "acquise" (validée, comptée dans le gain) ou "annulee".
     */
    public function commissions(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        $parStatut = VenteBoutique::statutsCommandeParStatutAffiche();

        $commissions = VenteBoutique::where('livreur_id', $request->user()->id)
            ->with(['commande.lignes.produit', 'commande.client.user'])
            ->latest('id')
            ->paginate(paginate_per_page($request))
            ->through(function (VenteBoutique $vente) use ($parStatut) {
                $commande = $vente->commande;
                $client = $commande->client?->user;

                return [
                    'id' => $vente->id,
                    'nom_produit' => $commande->lignes->first()?->produit?->nom_produit,
                    'client' => trim(($client?->prenom ?? '').' '.($client?->nom ?? '')),
                    'date' => $commande->date_commande,
                    'montant' => (float) $vente->commission,
                    'statut' => match (true) {
                        in_array($commande->statut_commande, $parStatut['annulee'], true) => 'annulee',
                        in_array($commande->statut_commande, $parStatut['en_attente'], true) => 'en_attente',
                        default => 'acquise',
                    },
                ];
            });

        return $this->success($commissions);
    }

    /** Onglet "Mes retraits" : ses demandes, plus récentes d'abord. */
    public function retraits(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        return $this->success(
            DemandeRetrait::where('user_id', $request->user()->id)
                ->latest('id')
                ->paginate(paginate_per_page($request))
                ->through(fn (DemandeRetrait $retrait) => $this->formaterRetrait($retrait))
        );
    }

    /**
     * Demande de retrait vers un numéro Mobile Money : minimum
     * RETRAIT_MONTANT_MINIMUM, jamais plus que la commission disponible. Le
     * montant est immédiatement réservé (déduit du disponible) jusqu'à la
     * décision de l'Admin. Le verrou sur la ligne du livreur empêche deux
     * demandes simultanées de dépasser ensemble le solde.
     */
    public function demander(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->type_utilisateur === ROLE_LIVREUR, 403);

        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:'.RETRAIT_MONTANT_MINIMUM],
            'operateur' => ['required', Rule::in(OPERATEURS_RETRAIT)],
            'telephone' => ['required', 'string', 'max:30', function (string $attribut, mixed $valeur, \Closure $echec) {
                if (strlen(preg_replace('/\D/', '', (string) $valeur)) < 8) {
                    $echec('Le numéro doit contenir au moins 8 chiffres.');
                }
            }],
        ], [
            'montant.min' => 'Le retrait minimum est de '.number_format(RETRAIT_MONTANT_MINIMUM, 0, ',', ' ').' FCFA.',
        ]);

        $retrait = DB::transaction(function () use ($user, $data) {
            Livreur::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $disponible = PortefeuilleCommissions::resume($user)['disponible'];
            if ($data['montant'] > $disponible) {
                throw ValidationException::withMessages([
                    'montant' => ['Montant supérieur à ta commission disponible ('.number_format($disponible, 0, ',', ' ').' FCFA).'],
                ]);
            }

            return DemandeRetrait::create([
                'user_id' => $user->id,
                'montant' => $data['montant'],
                'operateur' => $data['operateur'],
                'telephone' => normaliser_numero_ci($data['telephone']),
                'statut' => STATUT_RETRAIT_EN_ATTENTE,
            ]);
        });

        return $this->success($this->formaterRetrait($retrait), status: 201);
    }

    /** Annulation par le livreur, tant que l'Admin n'a pas statué — le montant redevient disponible. */
    public function annuler(Request $request, DemandeRetrait $retrait): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR && $retrait->user_id === $request->user()->id, 403);

        if (! $retrait->estEnAttente()) {
            throw ValidationException::withMessages(['statut' => ['Cette demande a déjà été traitée.']]);
        }

        $retrait->update(['statut' => STATUT_RETRAIT_ANNULE]);

        return $this->success($this->formaterRetrait($retrait));
    }

    private function formaterRetrait(DemandeRetrait $retrait): array
    {
        return [
            'id' => $retrait->id,
            'montant' => (float) $retrait->montant,
            'operateur' => $retrait->operateur,
            'telephone' => $retrait->telephone,
            'statut' => $retrait->statut,
            'reference' => $retrait->reference,
            'remarque' => $retrait->remarque,
            'created_at' => $retrait->created_at,
            'traite_le' => $retrait->traite_le,
        ];
    }
}
