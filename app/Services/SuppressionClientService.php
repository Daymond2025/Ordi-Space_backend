<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\CommandePartenaire;
use App\Models\JournalAudit;
use App\Models\User;
use App\Services\Partenaire\StockPartenaireService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Suppression DÉFINITIVE d'un client par l'Admin : son compte, ses accès
 * (jetons, rôles) et tout ce qui lui est rattaché — commandes (lignes,
 * livraisons, paiements, garanties, messages), commandes chez un partenaire,
 * adresses, panier, SAV, réclamations (et leurs preuves), Garantix,
 * privilèges, portefeuille, notifications, fichiers.
 *
 * Ordre imposé par les clés étrangères "RESTRICT" (supprimées à la main
 * avant le compte, le reste part en cascade). Le stock réservé par les
 * commandes encore en cours est remis (Ordi'Space et partenaire). Les
 * écritures financières des tiers (fournisseurs, commerciaux) sont gardées
 * pour la comptabilité : leur lien vers la commande passe juste à null.
 * Irréversible.
 */
class SuppressionClientService
{
    public function supprimer(User $client, User $admin): void
    {
        if ($client->type_utilisateur !== ROLE_CLIENT) {
            throw ValidationException::withMessages(['utilisateur' => ["Seul un compte client peut être supprimé ici."]]);
        }

        $id = $client->id;
        $fichiers = [];

        DB::transaction(function () use ($client, $id, &$fichiers) {
            $commandeIds = Commande::where('client_id', $id)->pluck('id');
            $ligneIds = DB::table('lignes_commande')->whereIn('commande_id', $commandeIds)->pluck('id');

            // Stock réservé par les commandes non livrées et non annulées : rendu au catalogue.
            Commande::with('lignes.produit')->whereIn('id', $commandeIds)
                ->whereNotIn('statut_commande', [STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_ANNULEE])
                ->get()
                ->each(fn (Commande $c) => $c->lignes->each(fn ($l) => $l->produit?->increment('quantite_stock', $l->quantite)));

            // Commandes chez un partenaire : stock sorti mais pas encore livré → rendu au partenaire.
            CommandePartenaire::with('lignes')->where('client_id', $id)->where('stock_sorti', true)
                ->where('statut', '!=', STATUT_COMMANDE_LIVREE)->get()
                ->each(fn (CommandePartenaire $c) => $c->lignes->each(fn ($l) => StockPartenaireService::mouvement(
                    $c->partenaire_id, $l->produit_id, $l->quantite, MOUVEMENT_STOCK_RETOUR_ANNULATION,
                    ['motif' => 'Compte client supprimé', 'commande_partenaire_id' => $c->id],
                )));

            $reclamationIds = DB::table('reclamations')
                ->where(fn ($q) => $q->where('client_id', $id)->orWhere('user_id', $id)->orWhereIn('commande_id', $commandeIds))
                ->pluck('id');
            $fichiers = [
                ...DB::table('preuves_reclamation')->whereIn('reclamation_id', $reclamationIds)->pluck('fichier')->all(),
                $client->getRawOriginal('photo'),
            ];

            // Tables en RESTRICT, d'abord.
            DB::table('abonnements_garantix')->where(fn ($q) => $q->where('client_id', $id)->orWhereIn('ligne_commande_id', $ligneIds))->delete();
            DB::table('reclamations')->whereIn('id', $reclamationIds)->delete();
            DB::table('demandes_sav')->where('client_id', $id)->delete();
            DB::table('utilisations_privilege')->where('client_id', $id)->delete();
            DB::table('transactions_portefeuille')->where('client_id', $id)->delete();
            DB::table('messages_partenaire')->where('auteur_id', $id)->delete();
            CommandePartenaire::where('client_id', $id)->delete();
            Commande::whereIn('id', $commandeIds)->delete();

            // Accès : jetons et rôles (tables polymorphes, sans cascade).
            $client->tokens()->delete();
            $client->syncRoles([]);
            DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $id)->delete();

            // Le reste (profil client, adresses, panier, notifications, journal…) part en cascade.
            $client->delete();
        });

        foreach (array_filter($fichiers) as $chemin) {
            Storage::disk(IMAGE_PRODUIT_DISQUE)->delete($chemin);
        }

        JournalAudit::enregistrer($admin->id, 'compte_client_supprime', 'utilisateur', "Compte client #{$id} supprimé définitivement.", acteurId: $admin->id);
        Log::info("Compte client #{$id} supprimé définitivement par l'administrateur #{$admin->id}.");
    }
}
