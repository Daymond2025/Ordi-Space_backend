<?php

namespace App\Services\Partenaire;

use App\Models\CommandePartenaire;
use App\Models\ConsultationPartenaire;
use App\Models\MessagePartenaire;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Formes de données du suivi des commandes partenaire — identiques à celles
 * du circuit Ordi'Space (MessageController / FournisseurController) pour
 * réutiliser les mêmes écrans : CommandeCarte, DonneesCommandeCreee,
 * statistiques produit, buckets "Commandes uniquement", rapport du jour.
 */
class FilPartenaire
{
    /** Mêmes 5 buckets que BUCKETS_COMMANDE (lib/statuts.ts des apps). */
    public const BUCKETS = [
        'nouvelle' => [STATUT_COMMANDE_EN_ATTENTE],
        'en_cours' => [STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_EN_PREPARATION, STATUT_COMMANDE_EN_LIVRAISON],
        'attention' => [STATUT_COMMANDE_REPORTEE, STATUT_COMMANDE_CLIENT_INJOIGNABLE, STATUT_COMMANDE_NUMERO_INCORRECT],
        'livree' => [STATUT_COMMANDE_LIVREE],
        'annulee' => [STATUT_COMMANDE_ANNULEE],
    ];

    /** = Produit::statistiquesCommandes(), restreint aux commandes de ce partenaire. */
    public static function statistiquesProduit(Collection $commandes): array
    {
        $livrees = $commandes->where('statut', STATUT_COMMANDE_LIVREE)->count();
        $annulees = $commandes->where('statut', STATUT_COMMANDE_ANNULEE)->count();

        return ['recues' => $commandes->count(), 'livrees' => $livrees, 'annulees' => $annulees, 'en_cours' => $commandes->count() - $livrees - $annulees];
    }

    public static function compteurs(int $partenaireId): array
    {
        $parStatut = CommandePartenaire::where('partenaire_id', $partenaireId)->selectRaw('statut, count(*) as total')->groupBy('statut')->pluck('total', 'statut');

        return collect(self::BUCKETS)->map(fn ($statuts) => (int) collect($statuts)->sum(fn ($s) => $parStatut[$s] ?? 0))->all();
    }

    /** Carte commande (fil produit + "Commandes uniquement") — suppose lignes.produit.images, client, localite et suivis.acteur chargés. */
    public static function carte(CommandePartenaire $commande, int $nouvellesActivites = 0): array
    {
        $produit = $commande->lignes->first()?->produit;
        $dernierSuivi = $commande->suivis->sortByDesc('date_heure')->first();

        return [
            'commande_id' => $commande->id,
            'reference' => $commande->reference,
            'photo' => $produit?->images->first()?->url_image,
            'nom_produit' => $produit?->nom_produit ?? 'Produit',
            'description' => $produit?->nom_produit,
            'nom_client' => trim(($commande->client?->prenom ?? '').' '.($commande->client?->nom ?? '')),
            // Pastille courte sur la carte (ville) ; l'adresse complète est dans le détail.
            'zone_localite' => $commande->mode_remise === MODE_REMISE_RETRAIT_BOUTIQUE ? 'Retrait en boutique' : $commande->localite?->nom,
            'telephone' => $commande->telephone_contact,
            'derniere_action' => $commande->updated_at,
            'statut' => $commande->statut,
            'nouvelles_activites' => $nouvellesActivites,
            'dernier_suivi' => $dernierSuivi ? [
                'texte' => $dernierSuivi->details,
                'acteur' => trim(($dernierSuivi->acteur?->prenom ?? '').' '.($dernierSuivi->acteur?->nom ?? '')),
                'date' => $dernierSuivi->date_heure,
            ] : null,
        ];
    }

    /** Instantané "Nouvelle commande" (DonneesCommandeCreee) — prix client uniquement, jamais le prix de cession. */
    public static function apercu(CommandePartenaire $commande): array
    {
        $commande->loadMissing(['lignes.produit.images', 'client', 'localite']);
        $ligne = $commande->lignes->first();

        return [
            'nom_produit' => $commande->lignes->map(fn ($l) => ($l->quantite > 1 ? "{$l->quantite} × " : '').$l->produit->nom_produit)->implode(', '),
            'photo' => $ligne?->produit->images->first()?->url_image,
            'prix_produit' => (float) $commande->montant_total,
            'nom_client' => trim(($commande->client?->prenom ?? '').' '.($commande->client?->nom ?? '')),
            'telephone' => $commande->telephone_contact,
            'zone_livraison' => $commande->mode_remise === MODE_REMISE_RETRAIT_BOUTIQUE ? 'Retrait en boutique' : self::zone($commande),
            'date_livraison_prevue' => null,
            'frais_livraison' => 0,
            'remise' => 0,
            'total' => (float) $commande->montant_total,
            'bonus_offerts' => null,
            'notes' => $commande->note,
        ];
    }

    /** Adresse de livraison, préfixée de la ville seulement si elle n'y figure pas déjà. */
    public static function zone(CommandePartenaire $commande): ?string
    {
        $ville = $commande->localite?->nom;
        $adresse = $commande->adresse_livraison;

        if ($adresse && $ville && mb_stripos($adresse, $ville) !== false) {
            return $adresse;
        }

        return collect([$ville, $adresse])->filter()->implode(', ') ?: null;
    }

    /** Messages non lus par fil commande (badge rouge des cartes). @return array<int, int> */
    public static function nonLusParCommande(User $lecteur, int $partenaireId, Collection $commandeIds): array
    {
        $consultations = ConsultationPartenaire::where('user_id', $lecteur->id)->where('cible', 'commande')
            ->where('partenaire_id', $partenaireId)->whereIn('cible_id', $commandeIds)->pluck('consulte_le', 'cible_id');

        return MessagePartenaire::whereIn('commande_partenaire_id', $commandeIds)->where('auteur_id', '!=', $lecteur->id)
            ->get(['commande_partenaire_id', 'date_envoi'])
            ->groupBy('commande_partenaire_id')
            ->map(fn ($msgs, $id) => $msgs->filter(fn ($m) => ! isset($consultations[$id]) || $m->date_envoi > $consultations[$id])->count())
            ->all();
    }

    /** Message système "Nouvelle commande" dans le fil produit du partenaire. */
    public static function publierCommandeCreee(CommandePartenaire $commande): void
    {
        MessagePartenaire::create([
            'partenaire_id' => $commande->partenaire_id,
            'produit_id' => $commande->lignes->first()?->produit_id,
            'auteur_id' => $commande->client_id,
            'type' => TYPE_MESSAGE_COMMANDE_CREEE,
            'contenu' => "Nouvelle commande {$commande->reference}",
            'donnees' => self::apercu($commande),
            'date_envoi' => now(),
        ]);
    }

    /**
     * Rapport du jour (activité d'hier) pour ce produit chez ce partenaire —
     * même règle que MessageController::genererRapportQuotidienSiNecessaire().
     */
    public static function genererRapportSiNecessaire(int $partenaireId, Produit $produit): void
    {
        $existe = MessagePartenaire::where('partenaire_id', $partenaireId)->where('produit_id', $produit->id)
            ->where('type', TYPE_MESSAGE_RAPPORT)->whereDate('date_envoi', today())->exists();
        if ($existe) {
            return;
        }

        $hier = today()->subDay();
        $commandes = CommandePartenaire::where('partenaire_id', $partenaireId)
            ->whereHas('lignes', fn ($q) => $q->where('produit_id', $produit->id))
            ->whereDate('created_at', $hier)->get();
        $auteurId = User::where('email', AGENT_IA_EMAIL)->where('type_utilisateur', ROLE_COMMERCIAL)->value('id');

        if ($commandes->isEmpty() || ! $auteurId) {
            return;
        }

        $donnees = [
            'date' => $hier->toDateString(),
            'envoyees' => $commandes->count(),
            'validees' => $commandes->whereIn('statut', STATUTS_CMD_PARTENAIRE_STOCK_SORTI)->count(),
            'reportees' => $commandes->where('statut', STATUT_COMMANDE_REPORTEE)->count(),
            'non_livre' => $commandes->whereIn('statut', [STATUT_COMMANDE_CLIENT_INJOIGNABLE, STATUT_COMMANDE_NUMERO_INCORRECT])->count(),
            'annulees' => $commandes->where('statut', STATUT_COMMANDE_ANNULEE)->count(),
        ];

        MessagePartenaire::create([
            'partenaire_id' => $partenaireId,
            'produit_id' => $produit->id,
            'auteur_id' => $auteurId,
            'type' => TYPE_MESSAGE_RAPPORT,
            'contenu' => sprintf('Rapport du %s — %d commande(s) envoyée(s), %d validée(s), %d reportée(s), %d non livrée(s), %d annulée(s).',
                $hier->format('d/m/Y'), $donnees['envoyees'], $donnees['validees'], $donnees['reportees'], $donnees['non_livre'], $donnees['annulees']),
            'donnees' => $donnees,
            'date_envoi' => now(),
        ]);
    }
}
