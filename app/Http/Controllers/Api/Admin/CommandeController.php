<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Coordinateur;
use App\Models\JournalAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommandeController extends Controller
{
    /**
     * Onglet "Commandes" de l'Admin : TOUTES les commandes de la plateforme,
     * quelle que soit leur origine (app client, commercial, Espace
     * Coordinateur, lien/vitrine/QR d'un livreur), plus récentes d'abord.
     *
     * Filtres : `statut` (n'importe quel statut de commande), `origine`
     * ("boutique" = apportée par un livreur, "directe" = toutes les autres) et
     * `q` (numéro de commande, nom ou téléphone du client, nom du produit).
     * Les compteurs par statut portent sur l'ensemble, pas sur la liste
     * filtrée, pour alimenter les onglets.
     */
    public function index(Request $request): JsonResponse
    {
        $statuts = [
            STATUT_COMMANDE_EN_ATTENTE, STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_EN_PREPARATION, STATUT_COMMANDE_EN_LIVRAISON,
            STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_ANNULEE, STATUT_COMMANDE_REPORTEE, STATUT_COMMANDE_CLIENT_INJOIGNABLE,
            STATUT_COMMANDE_NUMERO_INCORRECT,
        ];

        $data = $request->validate([
            'statut' => ['nullable', Rule::in($statuts)],
            'origine' => ['nullable', Rule::in(['boutique', 'directe'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $parStatut = array_fill_keys($statuts, 0);
        foreach (Commande::selectRaw('statut_commande, count(*) as nombre')->groupBy('statut_commande')->pluck('nombre', 'statut_commande') as $statut => $nombre) {
            $parStatut[$statut] = (int) $nombre;
        }

        $terme = trim($data['q'] ?? '');

        $commandes = Commande::with(['client.user', 'lignes.produit', 'canalVente', 'venteBoutique.livreur:id,nom,prenom', 'livraison.livreur.user:id,nom,prenom', 'paiement'])
            ->when($data['statut'] ?? null, fn ($q, $statut) => $q->where('statut_commande', $statut))
            ->when(($data['origine'] ?? null) === 'boutique', fn ($q) => $q->whereHas('venteBoutique'))
            ->when(($data['origine'] ?? null) === 'directe', fn ($q) => $q->whereDoesntHave('venteBoutique'))
            ->when($terme !== '', function ($q) use ($terme) {
                $q->where(function ($q) use ($terme) {
                    if (ctype_digit($terme)) {
                        $q->orWhere('id', (int) $terme);
                    }
                    $q->orWhereHas('client.user', fn ($u) => $u->where('nom', 'like', "%{$terme}%")
                        ->orWhere('prenom', 'like', "%{$terme}%")
                        ->orWhere('telephone', 'like', "%{$terme}%"))
                        ->orWhereHas('lignes.produit', fn ($p) => $p->where('nom_produit', 'like', "%{$terme}%"));
                });
            })
            ->latest('id')
            ->paginate(paginate_per_page($request))
            ->through(function (Commande $commande) {
                $client = $commande->client?->user;
                $vente = $commande->venteBoutique;
                $livreurLivraison = $commande->livraison?->livreur?->user;

                return [
                    'id' => $commande->id,
                    'date' => $commande->date_commande,
                    'statut_commande' => $commande->statut_commande,
                    'client' => trim(($client?->prenom ?? '').' '.($client?->nom ?? '')),
                    'client_telephone' => $client?->telephone,
                    'nom_produit' => $commande->lignes->first()?->produit?->nom_produit,
                    'nombre_lignes' => $commande->lignes->count(),
                    'montant_total' => (float) $commande->montant_total,
                    'total_a_payer' => $commande->montantNet(),
                    'canal' => $commande->canalVente?->nom_canal,
                    'origine' => $vente ? 'boutique' : 'directe',
                    // Vendeur (livreur qui a apporté la vente) et canal d'arrivée : manuelle, lien ou QR.
                    'vendeur' => $vente ? trim(($vente->livreur->prenom ?? '').' '.($vente->livreur->nom ?? '')) : null,
                    'vendeur_id' => $vente?->livreur_id,
                    'source' => $vente?->source,
                    'livreur' => $livreurLivraison ? trim(($livreurLivraison->prenom ?? '').' '.($livreurLivraison->nom ?? '')) : null,
                    'statut_paiement' => $commande->paiement?->statut_paiement,
                ];
            });

        return $this->success([
            'stats' => [
                'par_statut' => $parStatut,
                'total' => array_sum($parStatut),
            ],
            'commandes' => $commandes,
        ]);
    }

    /**
     * Fiche complète d'une commande pour la page "Détails commande" de
     * l'admin : produit, statut, paiement et les 3 acteurs du parcours
     * (commercial, fournisseur(s), livreur).
     */
    public function show(Commande $commande): JsonResponse
    {
        $commande->load([
            'client.user',
            'commercial.user',
            'coordinateur.user',
            'canalVente',
            'lignes.produit.images',
            'lignes.produit.categorie',
            'lignes.produit.fournisseur.user',
            'lignes.garantie',
            'livraison.livreur.user',
            'livraison.adresse',
            'paiement',
            'privilege',
            'parrain.user',
        ]);

        return $this->success($commande);
    }

    /**
     * Override admin : force une transition de statut sans passer par
     * l'acteur normalement responsable (coordinateur/fournisseur/livreur).
     * Utile pour débloquer une commande quand cet acteur n'a pas agi.
     * Rejoue les mêmes effets de bord que le flux normal (génération de
     * garantie, crédit de parrainage, restockage à l'annulation) pour ne
     * pas laisser les données incohérentes.
     */
    public function changerStatut(Request $request, Commande $commande): JsonResponse
    {
        $data = $request->validate([
            'statut_commande' => ['required', Rule::in([
                STATUT_COMMANDE_EN_ATTENTE,
                STATUT_COMMANDE_VALIDEE,
                STATUT_COMMANDE_EN_PREPARATION,
                STATUT_COMMANDE_EN_LIVRAISON,
                STATUT_COMMANDE_LIVREE,
                STATUT_COMMANDE_ANNULEE,
            ])],
            'livreur_id' => ['required_if:statut_commande,'.STATUT_COMMANDE_EN_LIVRAISON, 'nullable', 'exists:livreurs,user_id'],
        ]);

        $nouveauStatut = $data['statut_commande'];

        // coordinateur_id référence la table coordinateurs : on ne l'attribue
        // à l'admin que s'il est lui-même coordinateur, sinon on le laisse
        // tel quel (override sans acteur assigné).
        $adminEstCoordinateur = Coordinateur::where('user_id', $request->user()->id)->exists();

        $commande->appliquerChangementStatut(
            $nouveauStatut,
            livreurId: $data['livreur_id'] ?? null,
            coordinateurId: $adminEstCoordinateur ? $request->user()->id : null,
        );

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Statut de la commande n°{$commande->id} changé à « {$nouveauStatut} » par un administrateur.",
            commandeId: $commande->id,
            donnees: ['statut_apres' => $nouveauStatut],
        );

        return $this->success($commande->fresh(['livraison.livreur.user', 'lignes.garantie']));
    }
}
