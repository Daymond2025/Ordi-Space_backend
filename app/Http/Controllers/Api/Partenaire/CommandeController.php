<?php

namespace App\Http\Controllers\Api\Partenaire;

use App\Http\Controllers\Api\MessageController;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Models\CommandePartenaire;
use App\Models\ConsultationPartenaire;
use App\Models\LigneCommandePartenaire;
use App\Models\MessagePartenaire;
use App\Models\NotificationOrdispace;
use App\Models\Partenaire;
use App\Models\Produit;
use App\Models\SuiviCommandePartenaire;
use App\Services\Partenaire\CommandeParPartenaireService;
use App\Services\Partenaire\FilPartenaire;
use App\Services\Partenaire\StockPartenaireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Suivi des commandes partenaire — même processus que le circuit Ordi'Space
 * (mêmes statuts, même détail Suivie/Information, même fil de messages).
 * Lecture et messages : le partenaire propriétaire ou le coordinateur/admin
 * (routes "coordinateur/…"). Changer le statut : le partenaire seul — il
 * encaisse et livre lui-même ses clients.
 */
class CommandeController extends ControleurPartenaire
{
    private const RELATIONS_CARTE = ['client:id,nom,prenom', 'localite:id,nom', 'lignes.produit.images', 'suivis.acteur:id,nom,prenom'];

    /** "Commandes uniquement" : ?statut=a,b (bucket) — mêmes compteurs que GET /fournisseur/moi/commandes. */
    public function index(Request $request, ?Partenaire $partenaire = null): JsonResponse
    {
        $partenaire = $this->partenaireCible($request, $partenaire);

        $query = CommandePartenaire::with(self::RELATIONS_CARTE)->where('partenaire_id', $partenaire->user_id);
        if ($request->filled('statut')) {
            $query->whereIn('statut', explode(',', $request->string('statut')));
        }
        if ($request->filled('produit_id')) {
            $query->whereHas('lignes', fn ($q) => $q->where('produit_id', $request->integer('produit_id')));
        }

        $page = $query->latest('updated_at')->paginate(paginate_per_page($request));
        $nonLus = FilPartenaire::nonLusParCommande($request->user(), $partenaire->user_id, collect($page->items())->pluck('id'));
        $page->setCollection(collect($page->items())->map(fn (CommandePartenaire $c) => FilPartenaire::carte($c, $nonLus[$c->id] ?? 0)));

        return $this->success(['commandes' => $page, 'compteurs' => FilPartenaire::compteurs($partenaire->user_id)]);
    }

    /**
     * Commande passée depuis le catalogue pour un client : seulement nom,
     * numéro et lieu de livraison. En stock → validée ; sinon un
     * approvisionnement des articles manquants est renvoyé (écran "Confirmation" :
     * paiement fait hors application, à confirmer).
     */
    public function store(Request $request, CommandeParPartenaireService $service): JsonResponse
    {
        $partenaire = $this->partenaire($request);
        $data = $request->validate([
            'produit_id' => ['required', 'integer', 'exists:produits,id'],
            'quantite' => ['required', 'integer', 'min:1', 'max:20'],
            'prix_unitaire' => ['nullable', 'numeric', 'min:1', 'max:999999999'],
            'nom' => ['required', 'string', 'max:150'],
            // Au moins 8 chiffres, espaces et "+225" acceptés (normalisé ensuite).
            'telephone' => ['required', 'string', 'max:30', function ($attribut, $valeur, $echec) {
                if (strlen(preg_replace('/\D/', '', (string) $valeur)) < 8) {
                    $echec('Indiquez un numéro de téléphone valide.');
                }
            }],
            'lieu' => ['required', 'string', 'max:255'],
        ]);

        $resultat = $service->creer(
            $partenaire,
            Produit::findOrFail($data['produit_id']),
            $data['quantite'],
            isset($data['prix_unitaire']) ? (float) $data['prix_unitaire'] : null,
            ['nom' => $data['nom'], 'telephone' => $data['telephone'], 'lieu' => $data['lieu']],
            $request->user()->id,
        );

        $appro = $resultat['approvisionnement'];

        return $this->success([
            'commande' => self::presenter($resultat['commande']->fresh(['client:id,nom,prenom,telephone', 'localite:id,nom'])),
            'paiement' => $appro ? [
                'approvisionnement_id' => $appro->id,
                'reference' => $appro->reference,
                'montant' => (float) $appro->montant_total,
            ] : null,
        ], status: 201);
    }

    /** Détail (onglet "Information") : la commande + `meta.apercu` (carte "Nouvelle commande"). */
    public function show(Request $request, CommandePartenaire $commande): JsonResponse
    {
        $this->autoriserLecture($request, $commande);

        return $this->success(self::presenter($commande->load(['client:id,nom,prenom,telephone', 'localite:id,nom'])), meta: [
            'apercu' => FilPartenaire::apercu($commande),
        ]);
    }

    public function suivi(Request $request, CommandePartenaire $commande): JsonResponse
    {
        $this->autoriserLecture($request, $commande);

        return $this->success($commande->suivis()->with('acteur:id,nom,prenom,type_utilisateur')->orderBy('date_heure')->orderBy('id')->get()
            ->map(fn (SuiviCommandePartenaire $s) => $s->presenter()));
    }

    public function messages(Request $request, CommandePartenaire $commande): JsonResponse
    {
        $this->autoriserLecture($request, $commande);
        ConsultationPartenaire::marquer($request->user()->id, 'commande', $commande->partenaire_id, $commande->id);

        return $this->success($commande->messages()->with('auteur:id,nom,prenom,type_utilisateur')->latest('date_envoi')->paginate(paginate_per_page($request)));
    }

    public function envoyerMessage(StoreMessageRequest $request, CommandePartenaire $commande): JsonResponse
    {
        $this->autoriserLecture($request, $commande);

        return $this->success(self::creerMessage($request, [
            'partenaire_id' => $commande->partenaire_id,
            'produit_id' => $commande->lignes()->value('produit_id'),
            'commande_partenaire_id' => $commande->id,
        ])->load('auteur:id,nom,prenom,type_utilisateur'), status: 201);
    }

    /**
     * Feuille de statut — mêmes statuts que la feuille Coordinateur. Le stock
     * sort au premier statut "validée ou au-delà" (refusé si insuffisant :
     * il faut s'approvisionner) et revient à l'annulation. Livrée et annulée
     * sont définitives.
     */
    public function changerStatut(Request $request, CommandePartenaire $commande): JsonResponse
    {
        abort_unless($commande->partenaire_id === $this->partenaire($request)->user_id, 404);
        $data = $request->validate([
            'statut_commande' => ['required', Rule::in(CommandePartenaire::STATUTS_POSSIBLES)],
            'motif' => [Rule::requiredIf($request->input('statut_commande') === STATUT_COMMANDE_ANNULEE), 'nullable', 'string', 'max:500'],
        ]);
        $nouveau = $data['statut_commande'];

        $commande = DB::transaction(function () use ($request, $commande, $nouveau, $data) {
            $c = CommandePartenaire::with('lignes')->whereKey($commande->id)->lockForUpdate()->firstOrFail();
            $avant = $c->statut;

            if ($c->estFinale()) {
                throw ValidationException::withMessages(['statut_commande' => ['Cette commande est déjà '.($avant === STATUT_COMMANDE_LIVREE ? 'livrée' : 'annulée').'.']]);
            }
            if ($avant === $nouveau) {
                return $c;
            }

            if ($nouveau === STATUT_COMMANDE_ANNULEE) {
                return self::annulerEtRestocker($c, $data['motif'], $request->user()->id);
            }

            $attributs = ['statut' => $nouveau];
            if (in_array($nouveau, STATUTS_CMD_PARTENAIRE_STOCK_SORTI, true) && ! $c->stock_sorti) {
                if (! StockPartenaireService::suffisant($c->partenaire_id, $c->lignes)) {
                    throw ValidationException::withMessages(['statut_commande' => ['Stock insuffisant pour valider cette commande : approvisionnez-vous auprès d\'Ordi\'Space.']]);
                }
                foreach ($c->lignes as $ligne) {
                    StockPartenaireService::mouvement($c->partenaire_id, $ligne->produit_id, -$ligne->quantite, MOUVEMENT_STOCK_VENTE, [
                        'commande_partenaire_id' => $c->id, 'auteur_id' => $request->user()->id,
                    ]);
                }
                $attributs += ['stock_sorti' => true, 'acceptee_le' => now()];
            }
            if ($nouveau === STATUT_COMMANDE_EN_LIVRAISON) {
                $attributs['expediee_le'] = now();
            }
            if ($nouveau === STATUT_COMMANDE_LIVREE) {
                $attributs['livree_le'] = now();
            }

            $c->update($attributs);
            SuiviCommandePartenaire::enregistrer($c, 'statut_change', 'Statut : '.self::libelle($nouveau).'.', $request->user()->id, $avant, $nouveau);

            return $c;
        });

        $this->notifierClient($commande);

        return $this->success(self::presenter($commande->fresh(['client:id,nom,prenom,telephone', 'localite:id,nom'])));
    }

    /** À appeler dans une transaction, sur une commande verrouillée. */
    public static function annulerEtRestocker(CommandePartenaire $commande, string $motif, int $auteurId): CommandePartenaire
    {
        if ($commande->stock_sorti) {
            foreach ($commande->lignes as $ligne) {
                StockPartenaireService::mouvement($commande->partenaire_id, $ligne->produit_id, $ligne->quantite, MOUVEMENT_STOCK_RETOUR_ANNULATION, [
                    'commande_partenaire_id' => $commande->id, 'auteur_id' => $auteurId,
                ]);
            }
        }

        $avant = $commande->statut;
        $commande->update(['statut' => STATUT_COMMANDE_ANNULEE, 'stock_sorti' => false, 'motif_annulation' => $motif, 'annulee_le' => now()]);
        SuiviCommandePartenaire::enregistrer($commande, 'statut_change', "Commande annulée : {$motif}", $auteurId, $avant, STATUT_COMMANDE_ANNULEE);

        return $commande;
    }

    /** Vue partenaire/admin : prix payé, prix de cession et bénéfice par ligne (jamais montrée au client). */
    public static function presenter(CommandePartenaire $commande): array
    {
        $commande->loadMissing(['lignes.produit.images', 'lignes.produit.categorie']);

        return [
            ...collect($commande->toArray())->except('suivis')->all(),
            'statut_commande' => $commande->statut,
            'date_commande' => $commande->created_at,
            'benefice' => $commande->benefice(),
            'lignes' => $commande->lignes->map(fn (LigneCommandePartenaire $l) => [
                'id' => $l->id,
                'produit' => self::presenterProduit($l->produit),
                'quantite' => $l->quantite,
                'prix_unitaire' => (float) $l->prix_unitaire,
                'prix_cession_unitaire' => (float) $l->prix_cession_unitaire,
                'benefice' => ((float) $l->prix_unitaire - (float) $l->prix_cession_unitaire) * $l->quantite,
            ]),
        ];
    }

    public static function creerMessage(StoreMessageRequest $request, array $cible): MessagePartenaire
    {
        $donnees = $request->hasFile('fichier')
            ? MessageController::inferTypeEtStocker($request->file('fichier'), $request->string('type')->toString() ?: null)
            : ['type' => TYPE_MESSAGE_TEXTE, 'contenu' => $request->string('contenu')->toString()];

        return MessagePartenaire::create([...$cible, ...$donnees, 'auteur_id' => $request->user()->id, 'date_envoi' => now()]);
    }

    public static function libelle(string $statut): string
    {
        return [
            STATUT_COMMANDE_EN_ATTENTE => 'En attente', STATUT_COMMANDE_VALIDEE => 'Validée',
            STATUT_COMMANDE_EN_PREPARATION => 'En attente de livraison', STATUT_COMMANDE_EN_LIVRAISON => 'Livraison en cours',
            STATUT_COMMANDE_LIVREE => 'Livrée', STATUT_COMMANDE_ANNULEE => 'Annulée', STATUT_COMMANDE_REPORTEE => 'Reportée',
            STATUT_COMMANDE_CLIENT_INJOIGNABLE => 'Client injoignable', STATUT_COMMANDE_NUMERO_INCORRECT => 'Numéro incorrect',
        ][$statut] ?? $statut;
    }

    private function autoriserLecture(Request $request, CommandePartenaire $commande): void
    {
        abort_unless($commande->estAccessiblePar($request->user()), 404);
    }

    private function notifierClient(CommandePartenaire $commande): void
    {
        NotificationOrdispace::create([
            'user_id' => $commande->client_id,
            'type_notification' => 'commande_partenaire_'.$commande->statut,
            'titre' => 'Commande '.mb_strtolower(self::libelle($commande->statut)),
            'contenu' => "Votre commande {$commande->reference} : ".self::libelle($commande->statut).'.',
            'lu' => false,
            'date_envoi' => now(),
        ]);
    }
}
