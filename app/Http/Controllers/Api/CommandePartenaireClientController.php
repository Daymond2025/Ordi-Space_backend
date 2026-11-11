<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Partenaire\CommandeController as CommandePartenaireController;
use App\Http\Controllers\Api\Partenaire\ControleurPartenaire;
use App\Http\Controllers\Controller;
use App\Models\CommandePartenaire;
use App\Models\NotificationOrdispace;
use App\Models\OffrePartenaire;
use App\Models\Partenaire;
use App\Models\Produit;
use App\Models\StockPartenaire;
use App\Models\SuiviCommandePartenaire;
use App\Services\Partenaire\FilPartenaire;
use App\Services\Partenaire\TarificationPartenaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Côté client : partenaires de sa ville pour un produit (il choisit chez
 * qui commander), commande chez le partenaire choisi, suivi et annulation.
 * Le client ne voit jamais le prix de cession ni le bénéfice.
 */
class CommandePartenaireClientController extends Controller
{
    /** ?localite_id= obligatoire : la ville de livraison du client. */
    public function partenairesPourProduit(Request $request, Produit $produit): JsonResponse
    {
        abort_unless($produit->estVisibleALaVente(), 404);
        $data = $request->validate(['localite_id' => ['required', 'integer', 'exists:localites,id']]);

        $offres = OffrePartenaire::with('partenaire')
            ->where('produit_id', $produit->id)->where('actif', true)
            ->whereHas('partenaire', fn ($q) => $q->actifs()->couvrant($data['localite_id']))
            ->get();

        $stocks = StockPartenaire::where('produit_id', $produit->id)
            ->whereIn('partenaire_id', $offres->pluck('partenaire_id'))->pluck('quantite', 'partenaire_id');

        $partenaires = $offres
            // Prix de cession relevé au-dessus du prix de revente : offre suspendue.
            ->filter(fn (OffrePartenaire $o) => (float) $o->prix_revente >= TarificationPartenaire::prixCession($o->partenaire, $produit))
            ->map(fn (OffrePartenaire $o) => [
                'partenaire_id' => $o->partenaire_id,
                'nom_boutique' => $o->partenaire->nom_boutique,
                'adresse_boutique' => $o->partenaire->adresse_boutique,
                'prix' => (float) $o->prix_revente,
                'en_stock' => (int) ($stocks[$o->partenaire_id] ?? 0) > 0,
            ])
            ->sortBy([['en_stock', 'desc'], ['prix', 'asc']])
            ->values();

        return $this->success([
            'produit' => ControleurPartenaire::presenterProduit($produit->load(['images', 'categorie'])),
            'prix_ordispace' => TarificationPartenaire::prixOrdispace($produit),
            'partenaires' => $partenaires,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $client = $request->user();
        abort_unless($client->type_utilisateur === ROLE_CLIENT, 403);

        $data = $request->validate([
            'partenaire_id' => ['required', 'integer', 'exists:partenaires,user_id'],
            'localite_id' => ['required', 'integer', 'exists:localites,id'],
            'mode_remise' => ['required', Rule::in([MODE_REMISE_LIVRAISON, MODE_REMISE_RETRAIT_BOUTIQUE])],
            'adresse_livraison' => ['required_if:mode_remise,'.MODE_REMISE_LIVRAISON, 'nullable', 'string', 'max:255'],
            'telephone_contact' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
            'lignes' => ['required', 'array', 'min:1', 'max:20'],
            'lignes.*.produit_id' => ['required', 'integer', 'distinct', 'exists:produits,id'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $partenaire = Partenaire::with('user')->findOrFail($data['partenaire_id']);
        if (! $partenaire->estActif() || ! $partenaire->localites()->whereKey($data['localite_id'])->exists()) {
            throw ValidationException::withMessages(['partenaire_id' => ["Ce partenaire ne dessert pas votre ville."]]);
        }

        $produits = Produit::whereIn('id', array_column($data['lignes'], 'produit_id'))->get()->keyBy('id');
        $offres = OffrePartenaire::where('partenaire_id', $partenaire->user_id)->where('actif', true)
            ->whereIn('produit_id', $produits->keys())->get()->keyBy('produit_id');
        $prixCession = TarificationPartenaire::prixCessionPour($partenaire, $produits->values());

        $lignes = [];
        foreach ($data['lignes'] as $i => $ligne) {
            $produit = $produits[$ligne['produit_id']];
            $offre = $offres->get($produit->id);

            // Prix toujours relu côté serveur : jamais un prix envoyé par le client.
            if (! $produit->estVisibleALaVente() || ! $offre || (float) $offre->prix_revente < $prixCession[$produit->id]) {
                throw ValidationException::withMessages(["lignes.{$i}.produit_id" => ["Ce produit n'est pas disponible chez ce partenaire."]]);
            }

            $lignes[] = [
                'produit_id' => $produit->id,
                'quantite' => $ligne['quantite'],
                'prix_unitaire' => (float) $offre->prix_revente,
                'prix_cession_unitaire' => $prixCession[$produit->id],
                'duree_garantie_mois' => $produit->duree_garantie_mois,
            ];
        }

        $commande = DB::transaction(function () use ($data, $client, $partenaire, $lignes) {
            $commande = CommandePartenaire::create([
                'reference' => CommandePartenaire::genererReference(),
                'partenaire_id' => $partenaire->user_id,
                'client_id' => $client->id,
                'localite_id' => $data['localite_id'],
                'mode_remise' => $data['mode_remise'],
                'adresse_livraison' => $data['mode_remise'] === MODE_REMISE_LIVRAISON ? $data['adresse_livraison'] : null,
                'telephone_contact' => normaliser_telephone($data['telephone_contact'] ?? $client->telephone ?? ''),
                'note' => $data['note'] ?? null,
                'statut' => STATUT_COMMANDE_EN_ATTENTE,
                'montant_total' => collect($lignes)->sum(fn ($l) => $l['prix_unitaire'] * $l['quantite']),
            ]);
            $commande->lignes()->createMany($lignes);
            SuiviCommandePartenaire::enregistrer($commande, 'commande_creee', 'Commande passée par le client.', $client->id, null, STATUT_COMMANDE_EN_ATTENTE);
            FilPartenaire::publierCommandeCreee($commande->load('lignes'));

            return $commande;
        });

        NotificationOrdispace::create([
            'user_id' => $partenaire->user_id,
            'type_notification' => 'commande_partenaire_nouvelle',
            'titre' => 'Nouvelle commande',
            'contenu' => "{$client->nom} a passé la commande {$commande->reference} (".number_format((float) $commande->montant_total, 0, ',', ' ').' F).',
            'lu' => false,
            'date_envoi' => now(),
        ]);

        return $this->success($this->presenter($commande), status: 201);
    }

    public function index(Request $request): JsonResponse
    {
        $page = CommandePartenaire::with(['partenaire', 'localite:id,nom', 'lignes.produit.images', 'lignes.produit.categorie'])
            ->where('client_id', $request->user()->id)
            ->latest('id')->paginate(paginate_per_page($request));

        $page->setCollection(collect($page->items())->map(fn (CommandePartenaire $c) => $this->presenter($c)));

        return $this->success($page);
    }

    public function show(Request $request, CommandePartenaire $commande): JsonResponse
    {
        abort_unless($commande->client_id === $request->user()->id, 404);

        return $this->success($this->presenter($commande));
    }

    /** Le client annule tant que le partenaire n'a pas sorti le produit de son stock. */
    public function annuler(Request $request, CommandePartenaire $commande): JsonResponse
    {
        abort_unless($commande->client_id === $request->user()->id, 404);

        $commande = DB::transaction(function () use ($request, $commande) {
            $c = CommandePartenaire::with('lignes')->whereKey($commande->id)->lockForUpdate()->firstOrFail();
            if (! in_array($c->statut, [STATUT_COMMANDE_EN_ATTENTE], true)) {
                throw ValidationException::withMessages(['statut' => ['Commande déjà acceptée : contactez le partenaire pour l\'annuler.']]);
            }

            return CommandePartenaireController::annulerEtRestocker($c, 'Annulée par le client', $request->user()->id);
        });

        return $this->success($this->presenter($commande));
    }

    /** Vue client : sans prix de cession ni bénéfice. */
    private function presenter(CommandePartenaire $commande): array
    {
        $commande->loadMissing(['partenaire.user:id,telephone', 'localite:id,nom', 'lignes.produit.images', 'lignes.produit.categorie']);

        return [
            'id' => $commande->id,
            'reference' => $commande->reference,
            'statut' => $commande->statut,
            'mode_remise' => $commande->mode_remise,
            'adresse_livraison' => $commande->adresse_livraison,
            'telephone_contact' => $commande->telephone_contact,
            'note' => $commande->note,
            'localite' => $commande->localite?->nom,
            'montant_total' => (float) $commande->montant_total,
            'motif_annulation' => $commande->motif_annulation,
            'partenaire' => [
                'id' => $commande->partenaire_id,
                'nom_boutique' => $commande->partenaire?->nom_boutique,
                'adresse_boutique' => $commande->partenaire?->adresse_boutique,
                'telephone' => $commande->partenaire?->user?->telephone,
            ],
            'lignes' => $commande->lignes->map(fn ($l) => [
                'id' => $l->id,
                'produit' => ControleurPartenaire::presenterProduit($l->produit),
                'quantite' => $l->quantite,
                'prix_unitaire' => (float) $l->prix_unitaire,
                'duree_garantie_mois' => $l->duree_garantie_mois,
            ]),
            'acceptee_le' => $commande->acceptee_le,
            'expediee_le' => $commande->expediee_le,
            'livree_le' => $commande->livree_le,
            'annulee_le' => $commande->annulee_le,
            'created_at' => $commande->created_at,
        ];
    }
}
