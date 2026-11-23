<?php

namespace App\Observers;

use App\Models\Produit;
use App\Services\NotificationService as N;
use Illuminate\Support\Facades\DB;

/** Fournisseur : son produit vient d'être validé ou refusé par la coordination. */
class ProduitObserver
{
    public function updated(Produit $produit): void
    {
        if (! $produit->wasChanged('statut_produit')) {
            return;
        }

        $fournisseurId = $produit->getRawOriginal('fournisseur_id');
        if (! $fournisseurId) {
            return;
        }

        [$type, $titre, $contenu] = match ($produit->statut_produit) {
            STATUT_PRODUIT_VALIDE => ['produit_valide', 'Produit validé', "Votre produit « {$produit->nom_produit} » est publié."],
            STATUT_PRODUIT_REJETE => ['produit_refuse', 'Produit refusé', "Votre produit « {$produit->nom_produit} » a été refusé. Consultez le motif et corrigez-le."],
            default => [null, null, null],
        };

        if ($type === null) {
            return;
        }

        $id = $produit->id;
        DB::afterCommit(fn () => N::sansEchec(fn () => N::notifier((int) $fournisseurId, $type, $titre, $contenu, "/produits/{$id}")));
    }
}
