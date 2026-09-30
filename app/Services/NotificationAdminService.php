<?php

namespace App\Services;

use App\Models\NotificationOrdispace;
use App\Models\User;

/**
 * Diffuse une notification (table notifications_ordispace, déjà consommée
 * par n'importe quel rôle via GET/PATCH /moi/notifications, voir
 * MoiController) à TOUS les administrateurs — l'admin est "l'œil central" du
 * système, il doit être notifié des infos entrantes significatives
 * (produit à valider, réclamation, panne déclarée, retrait demandé…) sans
 * avoir à les découvrir en naviguant chaque écran. Style volontairement plat,
 * comme NotificationOrdispace::create() ailleurs dans le projet.
 */
class NotificationAdminService
{
    public static function notifierTousLesAdmins(string $type, string $titre, string $contenu): void
    {
        $adminIds = User::where('type_utilisateur', ROLE_ADMINISTRATEUR)->pluck('id');

        $maintenant = now();
        $lignes = $adminIds->map(fn ($id) => [
            'user_id' => $id,
            'type_notification' => $type,
            'titre' => $titre,
            'contenu' => $contenu,
            'lu' => false,
            'date_envoi' => $maintenant,
        ])->all();

        if ($lignes !== []) {
            NotificationOrdispace::insert($lignes);
        }
    }
}
