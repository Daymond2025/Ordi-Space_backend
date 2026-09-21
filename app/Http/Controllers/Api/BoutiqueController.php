<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coordinateur;
use App\Models\Fournisseur;
use App\Models\LienAffilie;
use App\Models\LigneCommande;
use App\Models\Localite;
use App\Models\Produit;
use App\Models\VenteBoutique;
use App\Models\Vitrine;
use Illuminate\Http\JsonResponse;
use App\Services\CommandePubliqueService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Boutique" — le Livreur revend des produits publiés par Fournisseur/
 * Coordinateur/Admin en échange d'une commission (`commission_revente` du
 * produit). Couvre la génération du lien affilié partageable (bouton
 * "Vendre ce produit") et la liste de ses ventes ("Centre des ventes").
 */
class BoutiqueController extends Controller
{
    /**
     * Récupère (ou crée) le lien affilié du livreur connecté pour ce
     * produit — idempotent : re-cliquer "Vendre ce produit" renvoie
     * toujours le même lien, jamais un doublon.
     */
    public function genererLien(Request $request, Produit $produit): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);
        abort_unless($produit->estVisibleALaVente(), 404);

        $lien = LienAffilie::firstOrCreate(
            ['produit_id' => $produit->id, 'livreur_id' => $request->user()->id],
            ['code' => LienAffilie::genererCode()]
        );

        return $this->success([
            'code' => $lien->code,
            'url' => $lien->url(),
        ]);
    }

    /**
     * "Je passe la commande" (détail produit) : le livreur saisit lui-même la
     * commande d'un client — nom, téléphone et ville — et l'envoie à l'équipe.
     * Elle arrive "en attente" chez l'Admin/Coordinateur, comme toute commande,
     * et devient une vente "commande manuelle" du livreur (commission figée,
     * acquise à la validation). Seuls les produits ouverts à la revente
     * (ceux de la Boutique) peuvent être commandés ainsi.
     */
    public function commander(Request $request, CommandePubliqueService $service): JsonResponse
    {
        $vendeur = $request->user();
        abort_unless($vendeur->type_utilisateur === ROLE_LIVREUR, 403);

        $data = $request->validate([
            'produit_id' => ['required', 'integer'],
            'nom' => ['required', 'string', 'max:100'],
            'telephone' => ['required', 'string', 'max:30', function (string $attribut, mixed $valeur, \Closure $echec) {
                if (strlen(preg_replace('/\D/', '', (string) $valeur)) < 8) {
                    $echec('Le numéro de téléphone doit contenir au moins 8 chiffres.');
                }
            }],
            'localite_id' => ['required', 'integer', 'exists:localites,id'],
        ]);

        $produit = Produit::where('statut_produit', STATUT_PRODUIT_VALIDE)
            ->where('type_livraison', TYPE_LIVRAISON_PHYSIQUE)
            ->whereNotNull('commission_revente')
            ->find($data['produit_id']);

        if (! $produit) {
            throw ValidationException::withMessages(['produit_id' => ["Ce produit n'est pas ouvert à la revente."]]);
        }

        $localite = Localite::findOrFail($data['localite_id']);

        $commande = $service->creer($vendeur, $produit, 1, [
            'nom' => $data['nom'],
            'telephone' => $data['telephone'],
            'localite_id' => $localite->id,
            // Le formulaire ne demande que la ville : l'adresse exacte est confirmée par téléphone à la validation.
            'adresse' => "{$localite->nom} — adresse à confirmer avec le client",
        ], 'manuelle');

        return $this->success([
            'reference' => $commande->id,
            'nom_produit' => $produit->nom_produit,
            'montant_produits' => (float) $commande->montant_total,
            'frais_livraison' => (float) $commande->frais_livraison,
            'total_a_payer' => $commande->montantNet(),
        ], status: 201);
    }

    /** Fiche du fournisseur d'un produit (détail produit) — null pour un produit publié directement par l'Admin. */
    public function fournisseurProduit(Request $request, Produit $produit): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);
        abort_unless($produit->estVisibleALaVente(), 404);

        return $this->success(['fournisseur' => $produit->fournisseur?->fichePourLivreur()]);
    }

    /**
     * Fournisseurs des produits que le livreur revend (produits dont il a un lien
     * ou une vente) — pour le profil Boutique. Un fournisseur sans aucun moyen
     * de le joindre ni de le situer est écarté : sa carte n'aiderait à rien.
     */
    public function fournisseurs(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);
        $livreurId = $request->user()->id;

        $produitIds = LienAffilie::where('livreur_id', $livreurId)->pluck('produit_id')
            ->merge(
                LigneCommande::whereIn('commande_id', VenteBoutique::where('livreur_id', $livreurId)->select('commande_id'))->pluck('produit_id')
            )
            ->unique();

        $fournisseurs = Fournisseur::with('user')
            ->whereIn('user_id', Produit::whereIn('id', $produitIds)->whereNotNull('fournisseur_id')->select('fournisseur_id'))
            ->get()
            ->map(fn (Fournisseur $fournisseur) => $fournisseur->fichePourLivreur())
            ->filter(fn (array $fiche) => $fiche['telephone'] || $fiche['adresse'] || $fiche['lien_maps'])
            ->values();

        return $this->success($fournisseurs);
    }

    /**
     * "Centre des ventes" : les ventes du livreur connecté, plus récentes
     * d'abord, avec les compteurs de l'en-tête et des filtres. Les compteurs
     * ignorent volontairement le filtre `statut` demandé — ils servent
     * justement à afficher "4 En cours / 3 En attente…" sur chaque pastille.
     * `statut` (en_cours|en_attente|livree|annulee) et `source`
     * (SOURCES_VENTE_BOUTIQUE) filtrent uniquement la liste.
     */
    public function ventes(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        $data = $request->validate([
            'statut' => ['nullable', Rule::in(array_keys(VenteBoutique::statutsCommandeParStatutAffiche()))],
            'source' => ['nullable', Rule::in(SOURCES_VENTE_BOUTIQUE)],
        ]);

        $mesVentes = VenteBoutique::where('livreur_id', $request->user()->id);

        $parStatut = [];
        foreach (VenteBoutique::statutsCommandeParStatutAffiche() as $statut => $statutsCommande) {
            $parStatut[$statut] = (clone $mesVentes)
                ->whereHas('commande', fn ($q) => $q->whereIn('statut_commande', $statutsCommande))
                ->count();
        }

        $ventes = (clone $mesVentes)
            ->when($data['statut'] ?? null, fn ($q, $statut) => $q->whereHas(
                'commande',
                fn ($c) => $c->whereIn('statut_commande', VenteBoutique::statutsCommandeParStatutAffiche()[$statut])
            ))
            ->when($data['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->with(['commande.lignes.produit.images', 'commande.client.user'])
            ->latest('id')
            ->paginate(paginate_per_page($request))
            ->through(fn (VenteBoutique $vente) => $this->formaterVente($vente));

        return $this->success([
            'stats' => [
                'commandes' => array_sum($parStatut),
                'produits' => (clone $mesVentes)
                    ->join('lignes_commande', 'lignes_commande.commande_id', '=', 'ventes_boutique.commande_id')
                    ->distinct('lignes_commande.produit_id')
                    ->count('lignes_commande.produit_id'),
                'par_statut' => $parStatut,
            ],
            'ventes' => $ventes,
        ]);
    }

    /**
     * Onglet "Vente par lien" du Centre des ventes : un lien affilié par
     * produit que le livreur a partagé, avec ses visites et ce que les
     * commandes passées par ce lien sont devenues. Trié par dernière activité
     * (visite ou commande), la plus récente d'abord ; un lien jamais utilisé
     * retombe sur sa date de création.
     */
    public function liens(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR, 403);

        $liens = LienAffilie::where('livreur_id', $request->user()->id)
            ->with(['produit.images', 'ventes.commande'])
            ->get()
            ->sortByDesc(fn (LienAffilie $lien) => $lien->derniere_activite_le ?? $lien->created_at)
            ->values()
            ->map(fn (LienAffilie $lien) => $this->formaterLien($lien));

        return $this->success($liens);
    }

    /**
     * Page d'un lien de vente (tap sur une carte de "Vente par lien") : la
     * carte du lien, le gain qu'il a rapporté et les commandes passées par
     * lui. Gain = commissions des ventes dont la commande a été validée par
     * l'Admin/Coordinateur et n'a pas été annulée (une commande en attente
     * ou annulée ne rapporte rien) — voir VenteBoutique::statutsCommandeAcquerantCommission().
     */
    public function lien(Request $request, LienAffilie $lien): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_LIVREUR && $lien->livreur_id === $request->user()->id, 403);

        $lien->load(['produit.images', 'ventes.commande']);

        $ventes = $lien->ventes()
            ->with(['commande.lignes.produit.images', 'commande.client.user'])
            ->latest('id')
            ->paginate(paginate_per_page($request))
            ->through(fn (VenteBoutique $vente) => $this->formaterVente($vente));

        return $this->success([
            'lien' => $this->formaterLien($lien),
            'gain_total' => (float) $lien->ventes()
                ->whereHas('commande', fn ($q) => $q->whereIn('statut_commande', VenteBoutique::statutsCommandeAcquerantCommission()))
                ->sum('commission'),
            'ventes' => $ventes,
        ]);
    }

    /**
     * Onglet "Profil" de la Boutique : identité du livreur, ses totaux, son
     * lien de vente (vitrine unique donnant accès à toute la sélection) et
     * l'affiche A4 dont le QR pointe dessus. La vitrine est créée à la
     * première ouverture (idempotent).
     *
     * Les "livrées" d'un canal comptent les ventes livrées arrivées par ce
     * canal : `qr` pour l'affiche, `whatsapp` pour le lien partagé. Les clics
     * du lien additionnent ceux de la vitrine et les visites des liens par
     * produit. La commission totale suit la même règle que le gain d'un lien
     * (commandes validées, non annulées — voir lien()).
     */
    public function profil(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->type_utilisateur === ROLE_LIVREUR, 403);

        $vitrine = Vitrine::pour($user);
        $parStatut = VenteBoutique::statutsCommandeParStatutAffiche();

        $mesVentes = VenteBoutique::where('livreur_id', $user->id);
        $ventesLivrees = (clone $mesVentes)
            ->whereHas('commande', fn ($q) => $q->whereIn('statut_commande', $parStatut['livree']));

        $commissions = Produit::whereNotNull('commission_revente')
            ->where('commission_revente', '>', 0)
            ->where('statut_produit', STATUT_PRODUIT_VALIDE)
            ->where('quantite_stock', '>', 0);

        return $this->success([
            'livreur' => [
                'nom' => $user->nom,
                'prenom' => $user->prenom,
                'telephone' => $user->telephone,
                'photo' => $user->photo,
            ],
            'stats' => [
                'produits_vendus' => (int) LigneCommande::whereIn('commande_id', (clone $ventesLivrees)->select('commande_id'))->sum('quantite'),
                'commandes_livrees' => (clone $ventesLivrees)->count(),
                'commandes_annulees' => (clone $mesVentes)
                    ->whereHas('commande', fn ($q) => $q->whereIn('statut_commande', $parStatut['annulee']))
                    ->count(),
                'commission_totale' => (float) (clone $mesVentes)
                    ->whereHas('commande', fn ($q) => $q->whereIn('statut_commande', VenteBoutique::statutsCommandeAcquerantCommission()))
                    ->sum('commission'),
            ],
            // Carte "Ton coordinateur" — null tant que le livreur n'a aucune mission.
            'coordinateur' => Coordinateur::fichePourLivreur($user),
            'lien' => [
                'url' => $vitrine->url(),
                // Fourchette des commissions proposées sur les produits actuellement vendables.
                'commission_min' => (clone $commissions)->min('commission_revente') !== null ? (float) (clone $commissions)->min('commission_revente') : null,
                'commission_max' => (clone $commissions)->max('commission_revente') !== null ? (float) (clone $commissions)->max('commission_revente') : null,
                'clics' => $vitrine->clics + (int) LienAffilie::where('livreur_id', $user->id)->sum('vues'),
                'livrees' => (clone $ventesLivrees)->where('source', 'whatsapp')->count(),
            ],
            'affiche' => [
                'url_qr' => $vitrine->urlQr(),
                'scans' => $vitrine->scans,
                'livrees' => (clone $ventesLivrees)->where('source', 'qr')->count(),
            ],
        ]);
    }

    /** Forme "carte lien" (liste et page d'un lien) — suppose produit.images et ventes.commande chargés. */
    private function formaterLien(LienAffilie $lien): array
    {
        $produit = $lien->produit;
        $parStatut = array_fill_keys(array_keys(VenteBoutique::statutsCommandeParStatutAffiche()), 0);
        foreach ($lien->ventes as $vente) {
            $parStatut[$vente->statutAffiche()]++;
        }

        return [
            'id' => $lien->id,
            'code' => $lien->code,
            'url' => $lien->url(),
            'produit_id' => $produit->id,
            'nom_produit' => $produit->nom_produit,
            'image' => $produit->images->first()?->url_image,
            // Pastille verte : le produit est toujours vendable, donc le lien mène à un achat possible.
            'actif' => $produit->estVisibleALaVente() && $produit->quantite_stock > 0,
            'vues' => $lien->vues,
            'commandes' => $lien->ventes->count(),
            'par_statut' => $parStatut,
            'derniere_activite' => $lien->derniere_activite_le ?? $lien->created_at,
        ];
    }

    /** Forme "carte" de l'écran "Centre des ventes" (première ligne = produit affiché). */
    private function formaterVente(VenteBoutique $vente): array
    {
        $commande = $vente->commande;
        $produit = $commande->lignes->first()?->produit;
        $client = $commande->client?->user;

        return [
            'id' => $vente->id,
            'commande_id' => $commande->id,
            'nom_produit' => $produit?->nom_produit,
            'image' => $produit?->images->first()?->url_image,
            'specs' => array_values(array_filter([
                $produit?->processeur, $produit?->memoire_ram, $produit?->stockage, $produit?->taille,
            ])),
            'client' => trim(($client?->prenom ?? '').' '.($client?->nom ?? '')),
            'date' => $commande->date_commande,
            'prix_vente' => (float) $commande->montant_total,
            'commission' => (float) $vente->commission,
            'source' => $vente->source,
            'statut' => $vente->statutAffiche(),
        ];
    }
}
