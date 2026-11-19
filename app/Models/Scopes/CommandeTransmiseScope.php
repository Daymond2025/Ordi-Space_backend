<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Une commande programmée par un commercial (commandes.date_transmission
 * dans le futur) reste invisible pour la coordination, le fournisseur et les
 * livreurs jusqu'à cette date — une heure avant la livraison choisie. Pas de
 * tâche planifiée sur l'hébergement : la commande « apparaît » simplement
 * quand l'heure est passée. Le commercial qui l'a saisie et l'Admin la voient
 * toujours.
 */
class CommandeTransmiseScope implements Scope
{
    public const ROLES_CONCERNES = [ROLE_COORDINATEUR, ROLE_FOURNISSEUR, ROLE_LIVREUR];

    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user || ! in_array($user->type_utilisateur, self::ROLES_CONCERNES, true)) {
            return;
        }

        $colonne = $model->qualifyColumn('date_transmission');

        $builder->where(fn (Builder $q) => $q->whereNull($colonne)->orWhere($colonne, '<=', now()));
    }
}
