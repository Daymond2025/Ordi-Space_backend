<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Models\Adresse;
use App\Models\Commande;
use App\Models\ConsultationCommande;
use App\Models\ConsultationProduit;
use App\Models\JournalAudit;
use App\Models\LigneCommande;
use App\Models\Message;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Discussion façon WhatsApp — Espace Coordinateur (Phase 2). Un seul
 * contrôleur pour les deux contextes (produit ET commande) : la logique
 * d'inférence de type/stockage de fichier est identique, seuls
 * l'autorisation et le FK cible diffèrent.
 */
class MessageController extends Controller
{
    public function indexProduit(Request $request, Produit $produit): JsonResponse
    {
        abort_unless($produit->estAccessibleConversationPar($request->user()), 403);

        $this->marquerConsulte($request, $produit);

        return $this->success($this->messagesPagines($produit->messages(), $request));
    }

    public function storeProduit(StoreMessageRequest $request, Produit $produit): JsonResponse
    {
        abort_unless($produit->estAccessibleConversationPar($request->user()), 403);

        return $this->success($this->creerMessage($request, ['produit_id' => $produit->id]), status: 201);
    }

    public function indexCommande(Request $request, Commande $commande): JsonResponse
    {
        abort_unless($commande->estAccessibleConversationPar($request->user()), 403);

        $this->marquerConsulteCommande($request, $commande);

        return $this->success($this->messagesPagines($commande->messages(), $request));
    }

    public function storeCommande(StoreMessageRequest $request, Commande $commande): JsonResponse
    {
        abort_unless($commande->estAccessibleConversationPar($request->user()), 403);

        return $this->success($this->creerMessage($request, ['commande_id' => $commande->id]), status: 201);
    }

    /**
     * Discussion produit : messages (vraiment paginés) fusionnés avec les
     * commandes de ce produit (plafonnées, non paginées séparément — une
     * vraie pagination unifiée sur deux tables hétérogènes demanderait une
     * UNION SQL, disproportionné pour un volume V1 réaliste), triés par date.
     * ?statut= filtre uniquement les cartes commande, jamais les messages.
     */
    public function conversationProduit(Request $request, Produit $produit): JsonResponse
    {
        abort_unless($produit->estAccessibleConversationPar($request->user()), 403);

        $this->marquerConsulte($request, $produit);
        $this->genererRapportQuotidienSiNecessaire($produit);

        $messages = $this->messagesPagines($produit->messages(), $request);

        $commandesQuery = Commande::whereHas('lignes', fn ($q) => $q->where('produit_id', $produit->id))
            ->with(['client.user', 'livraison.adresse.localite']);

        if ($request->user()->type_utilisateur === ROLE_COMMERCIAL) {
            $commandesQuery->where('commercial_id', $request->user()->id);
        }

        if ($request->filled('statut') && $request->string('statut')->toString() !== 'tous') {
            $commandesQuery->whereIn('statut_commande', explode(',', $request->string('statut')));
        }

        $commandesCollection = $commandesQuery->latest('updated_at')->limit(50)->get();
        $commandeIds = $commandesCollection->pluck('id');

        // Badge non-lu et dernier événement du suivi — batchés (pas de N+1
        // sur les jusqu'à 50 cartes affichées).
        $consultationsCommande = ConsultationCommande::where('user_id', $request->user()->id)
            ->whereIn('commande_id', $commandeIds)
            ->pluck('consulte_le', 'commande_id');

        $messagesNonLusParCommande = Message::whereIn('commande_id', $commandeIds)
            ->where('auteur_id', '!=', $request->user()->id)
            ->get(['id', 'commande_id', 'date_envoi'])
            ->groupBy('commande_id');

        $dernierSuiviParCommande = JournalAudit::whereIn('commande_id', $commandeIds)
            ->with('acteur:id,nom,prenom')
            ->orderByDesc('date_heure')
            ->get()
            ->groupBy('commande_id')
            ->map(fn ($groupe) => $groupe->first());

        $commandes = $commandesCollection->map(function ($commande) use (
            $produit, $consultationsCommande, $messagesNonLusParCommande, $dernierSuiviParCommande
        ) {
            $consulteLe = $consultationsCommande->get($commande->id);
            $nouvellesActivites = ($messagesNonLusParCommande->get($commande->id) ?? collect())
                ->filter(fn ($m) => ! $consulteLe || $m->date_envoi > $consulteLe)
                ->count();

            return $this->formaterCommandeCarte($commande, $produit, $nouvellesActivites, $dernierSuiviParCommande->get($commande->id));
        });

        $items = $messages->getCollection()
            ->map(fn ($m) => ['type' => 'message', 'date' => $m->date_envoi, 'donnee' => $m])
            ->concat($commandes->map(fn ($c) => ['type' => 'commande', 'date' => $c['derniere_action'], 'donnee' => $c]))
            ->sortByDesc('date')
            ->values();

        return $this->success([
            'items' => $items,
            'en_tete' => $produit->statistiquesCommandes(),
        ], meta: [
            'messages' => [
                'current_page' => $messages->currentPage(), 'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(), 'total' => $messages->total(),
            ],
            'commandes_cartes' => ['total_affiche' => $commandes->count(), 'limite' => 50],
        ]);
    }

    private function formaterCommandeCarte(Commande $commande, Produit $produit, int $nouvellesActivites, ?JournalAudit $dernierSuivi): array
    {
        return [
            'commande_id' => $commande->id,
            'photo' => $produit->images->first()?->url_image,
            'nom_produit' => $produit->nom_produit,
            'description' => $produit->description,
            'nom_client' => trim(($commande->client?->user?->prenom ?? '').' '.($commande->client?->user?->nom ?? '')),
            'zone_localite' => $this->formaterZoneLocalite($commande->livraison?->adresse),
            'telephone' => $commande->client?->user?->telephone,
            'derniere_action' => $commande->updated_at,
            'statut' => $commande->statut_commande,
            'nouvelles_activites' => $nouvellesActivites,
            'dernier_suivi' => $dernierSuivi ? [
                'texte' => $dernierSuivi->details,
                'acteur' => trim(($dernierSuivi->acteur?->prenom ?? '').' '.($dernierSuivi->acteur?->nom ?? '')),
                'date' => $dernierSuivi->date_heure,
            ] : null,
        ];
    }

    /**
     * "Ville, Localité" quand l'adresse a une localité reconnue (frais de
     * livraison) — sinon juste la ville, texte libre historique.
     */
    private function formaterZoneLocalite(?Adresse $adresse): ?string
    {
        if (! $adresse) {
            return null;
        }

        return $adresse->localite ? "{$adresse->ville}, {$adresse->localite->nom}" : $adresse->ville;
    }

    /**
     * Génère à la demande (premier arrivant du jour) un message-rapport
     * résumant l'activité d'hier pour ce produit — pas de cron disponible
     * sur l'hébergement (contrainte actée en Phase 1). Limite assumée : les 5
     * compteurs reflètent le statut *actuel* des commandes reçues hier, pas
     * la date exacte du changement de statut (non tracée précisément).
     * `contenu` reste une phrase de repli courte (notifications) ; les
     * chiffres détaillés vivent dans `donnees` pour la carte "Rapport du
     * jour" côté front.
     */
    private function genererRapportQuotidienSiNecessaire(Produit $produit): void
    {
        $aDejaUnRapportAujourdHui = $produit->messages()
            ->where('type', TYPE_MESSAGE_RAPPORT)
            ->whereDate('date_envoi', today())
            ->exists();

        if ($aDejaUnRapportAujourdHui) {
            return;
        }

        $hier = today()->subDay();
        $commandesHier = Commande::whereHas('lignes', fn ($q) => $q->where('produit_id', $produit->id))
            ->whereDate('date_commande', $hier)
            ->get();

        if ($commandesHier->isEmpty()) {
            return;
        }

        $auteurId = User::where('email', AGENT_IA_EMAIL)->value('id');

        if (! $auteurId) {
            return;
        }

        $validees = $commandesHier->whereIn('statut_commande', [
            STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_EN_PREPARATION, STATUT_COMMANDE_EN_LIVRAISON, STATUT_COMMANDE_LIVREE,
        ])->count();
        $reportees = $commandesHier->where('statut_commande', STATUT_COMMANDE_REPORTEE)->count();
        $nonLivre = $commandesHier->whereIn('statut_commande', [
            STATUT_COMMANDE_CLIENT_INJOIGNABLE, STATUT_COMMANDE_NUMERO_INCORRECT,
        ])->count();
        $annulees = $commandesHier->where('statut_commande', STATUT_COMMANDE_ANNULEE)->count();

        Message::create([
            'produit_id' => $produit->id,
            'auteur_id' => $auteurId,
            'type' => TYPE_MESSAGE_RAPPORT,
            'contenu' => sprintf(
                'Rapport du %s — %d commande(s) envoyée(s), %d validée(s), %d reportée(s), %d non livrée(s), %d annulée(s).',
                $hier->format('d/m/Y'),
                $commandesHier->count(),
                $validees,
                $reportees,
                $nonLivre,
                $annulees
            ),
            'donnees' => [
                'date' => $hier->toDateString(),
                'envoyees' => $commandesHier->count(),
                'validees' => $validees,
                'reportees' => $reportees,
                'non_livre' => $nonLivre,
                'annulees' => $annulees,
            ],
            'date_envoi' => now(),
        ]);
    }

    private function marquerConsulte(Request $request, Produit $produit): void
    {
        ConsultationProduit::updateOrCreate(
            ['user_id' => $request->user()->id, 'produit_id' => $produit->id],
            ['consulte_le' => now()]
        );
    }

    private function marquerConsulteCommande(Request $request, Commande $commande): void
    {
        ConsultationCommande::updateOrCreate(
            ['user_id' => $request->user()->id, 'commande_id' => $commande->id],
            ['consulte_le' => now()]
        );
    }

    /**
     * Fil "produits à activité récente" (Space — Accueil) : produits ayant au
     * moins un message ou une commande, triés par activité la plus récente
     * (dernier message OU dernière commande, le plus récent des deux),
     * plafonné à 100 (dashboard — pas de vraie pagination en V1). Le tri se
     * fait en PHP plutôt qu'en SQL (GREATEST()/MAX(a,b) ne sont pas
     * portables SQLite/MySQL) — volume attendu compatible avec cette approche.
     */
    public function produitsActifs(Request $request): JsonResponse
    {
        $user = $request->user();

        $produits = Produit::query()
            ->when($user->type_utilisateur === ROLE_FOURNISSEUR, fn ($q) => $q->where('fournisseur_id', $user->id))
            ->when($user->type_utilisateur !== ROLE_FOURNISSEUR && $request->filled('fournisseur_id'), fn ($q) => $q->where('fournisseur_id', $request->integer('fournisseur_id')))
            ->where(fn ($q) => $q->whereHas('messages')->orWhereHas('lignesCommande'))
            ->with('images')
            ->withMax('messages', 'date_envoi')
            ->get();

        if ($produits->isEmpty()) {
            return $this->success([]);
        }

        $produitIds = $produits->pluck('id');

        $dernieresCommandes = LigneCommande::join('commandes', 'commandes.id', '=', 'lignes_commande.commande_id')
            ->whereIn('lignes_commande.produit_id', $produitIds)
            ->selectRaw('lignes_commande.produit_id, MAX(commandes.updated_at) as derniere')
            ->groupBy('lignes_commande.produit_id')
            ->pluck('derniere', 'produit_id');

        $consultations = ConsultationProduit::where('user_id', $user->id)
            ->whereIn('produit_id', $produitIds)
            ->pluck('consulte_le', 'produit_id');

        $resultats = $produits->map(function (Produit $produit) use ($dernieresCommandes, $consultations, $user) {
            $consulteLe = $consultations->get($produit->id);

            $derniereActivite = collect([$produit->messages_max_date_envoi, $dernieresCommandes->get($produit->id)])
                ->filter()
                ->max();

            return [
                'produit_id' => $produit->id,
                'nom_produit' => $produit->nom_produit,
                'photo' => $produit->images->first()?->url_image,
                'statistiques' => $produit->statistiquesCommandes(),
                'derniere_activite' => $derniereActivite,
                'nouvelles_activites' => Message::where('produit_id', $produit->id)
                    ->where('auteur_id', '!=', $user->id)
                    ->when($consulteLe, fn ($q) => $q->where('date_envoi', '>', $consulteLe))
                    ->count(),
            ];
        })->sortByDesc('derniere_activite')->take(100)->values();

        return $this->success($resultats);
    }

    private function messagesPagines(HasMany $relation, Request $request)
    {
        return $relation->with('auteur:id,nom,prenom,type_utilisateur')
            ->latest('date_envoi')
            ->paginate(paginate_per_page($request));
    }

    private function creerMessage(StoreMessageRequest $request, array $cible): Message
    {
        if ($request->hasFile('fichier')) {
            $donnees = $this->inferTypeEtStocker($request->file('fichier'), $request->string('type')->toString() ?: null);
        } else {
            $donnees = ['type' => TYPE_MESSAGE_TEXTE, 'contenu' => $request->string('contenu')->toString()];
        }

        return Message::create([
            ...$cible,
            ...$donnees,
            'auteur_id' => $request->user()->id,
            'date_envoi' => now(),
        ]);
    }

    /**
     * Détermine le type de message à partir de l'extension du fichier, puis
     * vérifie son poids contre le plafond propre à ce type (le FormRequest
     * ne valide que l'appartenance globale aux mimes pris en charge — le
     * plafond dépend du type inféré, indisponible à ce stade-là).
     */
    private function inferTypeEtStocker(UploadedFile $fichier, ?string $typeSouhaite): array
    {
        $extension = strtolower($fichier->getClientOriginalExtension());

        $correspondance = match (true) {
            in_array($extension, explode(',', IMAGE_MIMES_AUTORISES), true) =>
                [TYPE_MESSAGE_IMAGE, IMAGE_MAX_POIDS_KO, MESSAGE_IMAGE_DOSSIER],
            in_array($extension, explode(',', VIDEO_MIMES_AUTORISES), true) =>
                [TYPE_MESSAGE_VIDEO, VIDEO_MAX_POIDS_KO, MESSAGE_VIDEO_DOSSIER],
            in_array($extension, explode(',', AUDIO_MIMES_AUTORISES), true) =>
                [$typeSouhaite === TYPE_MESSAGE_NOTE_VOCALE ? TYPE_MESSAGE_NOTE_VOCALE : TYPE_MESSAGE_AUDIO, AUDIO_MAX_POIDS_KO, MESSAGE_AUDIO_DOSSIER],
            in_array($extension, explode(',', DOCUMENT_MIMES_AUTORISES), true) =>
                [TYPE_MESSAGE_DOCUMENT, DOCUMENT_MAX_POIDS_KO, MESSAGE_DOCUMENT_DOSSIER],
            default => throw ValidationException::withMessages(['fichier' => ['Type de fichier non pris en charge.']]),
        };

        [$type, $maxKo, $dossier] = $correspondance;
        $poidsKo = $fichier->getSize() / 1024;

        if ($poidsKo > $maxKo) {
            throw ValidationException::withMessages([
                'fichier' => ["Ce fichier dépasse la taille maximale autorisée ({$maxKo} Ko) pour ce type de contenu."],
            ]);
        }

        return ['type' => $type, 'fichier' => $fichier->store($dossier, IMAGE_PRODUIT_DISQUE)];
    }
}
