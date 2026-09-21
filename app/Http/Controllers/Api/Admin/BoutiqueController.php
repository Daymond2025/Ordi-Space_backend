<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\VenteBoutique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Suivi Admin des ventes "Boutique" des livreurs : les commandes qu'ils
 * apportent (par commande manuelle, lien ou QR) et les commissions qu'elles
 * rapportent. Ce sont des commandes ordinaires : leur validation se fait
 * comme d'habitude (PATCH /admin/commandes/{id}/statut ou Espace
 * Coordinateur) et c'est elle qui rend la commission acquise au livreur —
 * voir PortefeuilleCommissions.
 */
class BoutiqueController extends Controller
{
    /**
     * Toutes les ventes, plus récentes d'abord. `statut` (en_attente|en_cours|
     * livree|annulee), `source` (SOURCES_VENTE_BOUTIQUE) et `livreur_id`
     * filtrent la liste ; les compteurs, eux, portent sur l'ensemble (comme
     * côté livreur, pour afficher "à valider : 4" quel que soit le filtre).
     */
    public function commandes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'statut' => ['nullable', Rule::in(array_keys(VenteBoutique::statutsCommandeParStatutAffiche()))],
            'source' => ['nullable', Rule::in(SOURCES_VENTE_BOUTIQUE)],
            'livreur_id' => ['nullable', 'integer'],
        ]);

        $parStatutAffiche = VenteBoutique::statutsCommandeParStatutAffiche();

        $comptes = [];
        foreach ($parStatutAffiche as $statut => $statutsCommande) {
            $comptes[$statut] = VenteBoutique::whereHas('commande', fn ($q) => $q->whereIn('statut_commande', $statutsCommande))->count();
        }

        $commissionSomme = fn (array $statutsCommande) => (float) VenteBoutique::whereHas(
            'commande',
            fn ($q) => $q->whereIn('statut_commande', $statutsCommande)
        )->sum('commission');

        $ventes = VenteBoutique::with(['livreur:id,nom,prenom,telephone', 'commande.lignes.produit', 'commande.client.user'])
            ->when($data['statut'] ?? null, fn ($q, $statut) => $q->whereHas(
                'commande',
                fn ($c) => $c->whereIn('statut_commande', $parStatutAffiche[$statut])
            ))
            ->when($data['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($data['livreur_id'] ?? null, fn ($q, $livreurId) => $q->where('livreur_id', $livreurId))
            ->latest('id')
            ->paginate(paginate_per_page($request))
            ->through(function (VenteBoutique $vente) {
                $commande = $vente->commande;
                $client = $commande->client?->user;

                return [
                    'id' => $vente->id,
                    'commande_id' => $commande->id,
                    'livreur_id' => $vente->livreur_id,
                    'livreur' => trim(($vente->livreur->prenom ?? '').' '.($vente->livreur->nom ?? '')),
                    'livreur_telephone' => $vente->livreur->telephone,
                    'client' => trim(($client?->prenom ?? '').' '.($client?->nom ?? '')),
                    'client_telephone' => $client?->telephone,
                    'nom_produit' => $commande->lignes->first()?->produit?->nom_produit,
                    'prix_vente' => (float) $commande->montant_total,
                    'commission' => (float) $vente->commission,
                    'source' => $vente->source,
                    'statut' => $vente->statutAffiche(),
                    'statut_commande' => $commande->statut_commande,
                    'date' => $commande->date_commande,
                ];
            });

        return $this->success([
            'stats' => [
                'par_statut' => $comptes,
                // Commission que la validation des commandes en attente rendra acquise.
                'commission_a_valider' => $commissionSomme($parStatutAffiche['en_attente']),
                'commission_acquise' => $commissionSomme(VenteBoutique::statutsCommandeAcquerantCommission()),
            ],
            'commandes' => $ventes,
        ]);
    }
}
