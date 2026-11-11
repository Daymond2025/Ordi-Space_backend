<?php

namespace App\Http\Controllers\Api\Partenaire;

use App\Http\Requests\Message\StoreMessageRequest;
use App\Models\CommandePartenaire;
use App\Models\ConsultationPartenaire;
use App\Models\MessagePartenaire;
use App\Models\Partenaire;
use App\Models\Produit;
use App\Services\Partenaire\FilPartenaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Chat & commandes" du partenaire — mêmes formes que MessageController
 * (produitsActifs / conversationProduit), sur ses propres commandes et sa
 * propre conversation par produit. Coordinateur/admin : mêmes vues via
 * coordinateur/partenaires/{partenaire}/…
 */
class ConversationController extends ControleurPartenaire
{
    /** Liste des partenaires (sélecteur côté coordinateur). */
    public function partenaires(): JsonResponse
    {
        return $this->success(Partenaire::with(['user:id,nom,prenom,telephone,photo,statut_compte', 'localites:id,nom'])
            ->withCount('commandes')->orderBy('nom_boutique')->get());
    }

    /** Produits commandés chez le partenaire, activité la plus récente d'abord (forme ProduitActif). */
    public function produitsActifs(Request $request, ?Partenaire $partenaire = null): JsonResponse
    {
        $partenaire = $this->partenaireCible($request, $partenaire);
        $id = $partenaire->user_id;
        $lecteur = $request->user();

        $commandes = CommandePartenaire::with('lignes:id,commande_partenaire_id,produit_id')->where('partenaire_id', $id)->get();
        $parProduit = $commandes->flatMap(fn ($c) => $c->lignes->map(fn ($l) => ['produit_id' => $l->produit_id, 'commande' => $c]))
            ->groupBy('produit_id')->map(fn ($g) => $g->pluck('commande')->unique('id'));

        $produits = Produit::with('images')->whereIn('id', $parProduit->keys())->get()->keyBy('id');
        $consultations = ConsultationPartenaire::where('user_id', $lecteur->id)->where('cible', 'produit')->where('partenaire_id', $id)->pluck('consulte_le', 'cible_id');
        $messages = MessagePartenaire::where('partenaire_id', $id)->whereIn('produit_id', $parProduit->keys())
            ->where('auteur_id', '!=', $lecteur->id)->get(['produit_id', 'date_envoi'])->groupBy('produit_id');

        $liste = $parProduit->map(function ($cmds, $produitId) use ($produits, $consultations, $messages) {
            $vu = $consultations[$produitId] ?? null;
            $nouveaux = ($messages[$produitId] ?? collect())->filter(fn ($m) => ! $vu || $m->date_envoi > $vu)->count()
                + $cmds->filter(fn ($c) => ! $vu || $c->updated_at > $vu)->count();

            return [
                'produit_id' => $produitId,
                'nom_produit' => $produits[$produitId]->nom_produit,
                'photo' => $produits[$produitId]->images->first()?->url_image,
                'statistiques' => FilPartenaire::statistiquesProduit($cmds),
                'derniere_activite' => max($cmds->max('updated_at'), ($messages[$produitId] ?? collect())->max('date_envoi')),
                'nouvelles_activites' => $nouveaux,
                'epingle' => false,
            ];
        })->sortByDesc('derniere_activite')->values();

        return $this->success($liste);
    }

    /** Fil produit : messages + cartes commandes fusionnés, plus récent d'abord (forme ConversationProduit). */
    public function conversation(Request $request, Produit $produit, ?Partenaire $partenaire = null): JsonResponse
    {
        $partenaire = $this->partenaireCible($request, $partenaire);
        $id = $partenaire->user_id;

        FilPartenaire::genererRapportSiNecessaire($id, $produit);
        ConsultationPartenaire::marquer($request->user()->id, 'produit', $id, $produit->id);

        $messages = MessagePartenaire::with('auteur:id,nom,prenom,type_utilisateur')
            ->where('partenaire_id', $id)->where('produit_id', $produit->id)->whereNull('commande_partenaire_id')
            ->latest('date_envoi')->paginate(paginate_per_page($request));

        $commandesQuery = CommandePartenaire::with(['client:id,nom,prenom', 'localite:id,nom', 'lignes.produit.images', 'suivis.acteur:id,nom,prenom'])
            ->where('partenaire_id', $id)->whereHas('lignes', fn ($q) => $q->where('produit_id', $produit->id));
        $toutes = (clone $commandesQuery)->get(['id', 'statut']);
        $commandes = $commandesQuery->latest('updated_at')->limit(50)->get();
        $nonLus = FilPartenaire::nonLusParCommande($request->user(), $id, $commandes->pluck('id'));

        $items = collect($messages->items())
            ->map(fn ($m) => ['type' => 'message', 'date' => $m->date_envoi, 'donnee' => $m])
            ->concat($commandes->map(fn ($c) => ['type' => 'commande', 'date' => $c->updated_at, 'donnee' => FilPartenaire::carte($c, $nonLus[$c->id] ?? 0)]))
            ->sortByDesc('date')->values();

        return $this->success([
            'items' => $items,
            'en_tete' => FilPartenaire::statistiquesProduit($toutes),
            'produit' => ['id' => $produit->id, 'nom_produit' => $produit->nom_produit, 'photo' => $produit->images->first()?->url_image],
        ]);
    }

    public function envoyerMessage(StoreMessageRequest $request, Produit $produit, ?Partenaire $partenaire = null): JsonResponse
    {
        $partenaire = $this->partenaireCible($request, $partenaire);

        return $this->success(CommandeController::creerMessage($request, [
            'partenaire_id' => $partenaire->user_id,
            'produit_id' => $produit->id,
        ])->load('auteur:id,nom,prenom,type_utilisateur'), status: 201);
    }

    // Routes coordinateur/partenaires/{partenaire}/produits/{produit}/… :
    // Laravel passe les paramètres dans l'ordre de l'URL (partenaire, produit).

    public function conversationPourCoordinateur(Request $request, Partenaire $partenaire, Produit $produit): JsonResponse
    {
        return $this->conversation($request, $produit, $partenaire);
    }

    public function envoyerMessagePourCoordinateur(StoreMessageRequest $request, Partenaire $partenaire, Produit $produit): JsonResponse
    {
        return $this->envoyerMessage($request, $produit, $partenaire);
    }
}
