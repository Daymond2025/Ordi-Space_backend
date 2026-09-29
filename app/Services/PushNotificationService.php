<?php

namespace App\Services;

use App\Models\Livreur;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Notifications push web (navigateur) — pas de websocket ni d'app native
 * dans ce projet, c'est la seule façon réelle d'alerter un utilisateur qui
 * n'a pas l'app ouverte (écran "Recherche d'un livreur", app Fournisseur).
 * Style volontairement plat, comme NotificationOrdispace::create() ailleurs
 * dans le projet — pas le système de Notifications Laravel (jamais utilisé
 * ici malgré le trait Notifiable sur User).
 */
class PushNotificationService
{
    private function client(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => config('services.vapid.subject'),
                'publicKey' => config('services.vapid.public_key'),
                'privateKey' => config('services.vapid.private_key'),
            ],
        ]);
    }

    /**
     * Envoie à tous les abonnements de cet utilisateur (plusieurs appareils
     * possibles). Les abonnements expirés/révoqués (410/404) sont supprimés
     * au passage — un navigateur ne les renouvelle jamais de lui-même.
     */
    public function envoyer(User $user, string $titre, string $corps, array $donnees = []): void
    {
        $abonnements = PushSubscription::where('user_id', $user->id)->get();
        if ($abonnements->isEmpty()) {
            return;
        }

        $webPush = $this->client();
        $payload = json_encode(['titre' => $titre, 'corps' => $corps, 'donnees' => $donnees]);

        foreach ($abonnements as $abonnement) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $abonnement->endpoint,
                    'publicKey' => $abonnement->p256dh,
                    'authToken' => $abonnement->auth,
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $rapport) {
            if ($rapport->isSuccess()) {
                continue;
            }

            // Abonnement mort (navigateur désinstallé, permission révoquée...)
            // — on le supprime pour ne plus jamais réessayer dessus.
            if ($rapport->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $rapport->getEndpoint())->delete();
                continue;
            }

            // Toute autre erreur (clé VAPID mal configurée, service push
            // temporairement indisponible...) : on log sans faire échouer
            // l'action métier qui a déclenché l'envoi (marquerPreparee() ne
            // doit jamais échouer à cause d'une notification).
            Log::warning('Échec envoi notification push', [
                'endpoint' => $rapport->getEndpoint(),
                'raison' => $rapport->getReason(),
            ]);
        }
    }

    /**
     * Cible tous les livreurs actuellement "en ligne" (Livreur::disponible)
     * — seul signal réel de "cherche du travail maintenant" existant dans le
     * projet (zone_couverture est un texte libre jamais fiable pour filtrer,
     * voir Livreur::zone_couverture). Pas de notion de proximité géographique
     * réelle (aucune position GPS livreur en base).
     */
    public function envoyerAuxLivreursDisponibles(string $titre, string $corps, array $donnees = []): void
    {
        $livreurs = Livreur::where('disponible', true)->with('user')->get();

        foreach ($livreurs as $livreur) {
            if ($livreur->user) {
                $this->envoyer($livreur->user, $titre, $corps, $donnees);
            }
        }
    }
}
