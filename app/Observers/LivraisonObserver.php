<?php

namespace App\Observers;

use App\Models\Livraison;
use App\Services\NotificationService as N;
use Illuminate\Support\Facades\DB;

/**
 * Livreur : une mission vient de lui être assignée (par la coordination ou
 * par le fournisseur) — urgente, elle sonne jusqu'à ce qu'il l'ouvre.
 */
class LivraisonObserver
{
    public function saved(Livraison $livraison): void
    {
        // Un livreur a pris la livraison (acceptée ou assignée) : l'alerte du
        // vivier cesse de sonner chez les autres livreurs.
        if ($livraison->livreur_id && $livraison->wasChanged('livreur_id')) {
            $commandeId = $livraison->commande_id;
            DB::afterCommit(fn () => N::sansEchec(fn () => N::desamorcer('livraison_disponible', null, "Commande n°{$commandeId} ")));
        }

        if (! $livraison->livreur_id || (! $livraison->wasChanged('livreur_id') && ! $livraison->wasRecentlyCreated)) {
            return;
        }

        if ($livraison->statut_livraison !== STATUT_LIVRAISON_ASSIGNEE) {
            return;
        }

        $livreurId = $livraison->livreur_id;
        $id = $livraison->id;
        $commandeId = $livraison->commande_id;

        DB::afterCommit(fn () => N::sansEchec(fn () => N::notifier(
            $livreurId,
            'mission_assignee',
            'Nouvelle mission assignée',
            "La commande n°{$commandeId} t'a été assignée. Ouvre la mission pour l'accepter.",
            "/mission/{$id}",
            urgente: true,
        )));
    }
}
