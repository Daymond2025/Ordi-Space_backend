<?php

namespace App\Services;

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
        // Une par une (NotificationService) : chaque admin reçoit aussi le
        // push et la sonnerie de l'app Admin.
        NotificationService::notifier(
            User::where('type_utilisateur', ROLE_ADMINISTRATEUR)->pluck('id'),
            $type,
            $titre,
            $contenu,
        );
    }
}
