<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\Commercial;
use App\Models\NotificationOrdispace;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Point d'envoi unique des notifications « persistantes avec sonnerie » :
 * chaque notification est enregistrée (notifications_ordispace, lue/non lue)
 * puis poussée sur le téléphone (Web Push, voir NotificationOrdispace::booted).
 * Les apps interrogent GET /moi/notifications/etat toutes les ~10 s : à chaque
 * nouvelle notification elles sonnent et affichent un bandeau ; une
 * notification « urgente » sonne en boucle tant qu'elle n'est pas lue.
 */
class NotificationService
{
    /**
     * @param  iterable<int|null>|int|null  $destinataires  ids utilisateurs (null ignorés)
     */
    public static function notifier(
        iterable|int|null $destinataires,
        string $type,
        string $titre,
        string $contenu,
        ?string $lien = null,
        bool $urgente = false,
    ): void {
        $ids = collect(is_iterable($destinataires) ? $destinataires : [$destinataires])
            ->filter()
            ->unique()
            // Jamais à l'auteur de l'action : il sait déjà ce qu'il vient de faire.
            ->reject(fn ($id) => $id === auth()->id());

        foreach ($ids as $id) {
            try {
                NotificationOrdispace::create([
                    'user_id' => $id,
                    'type_notification' => $type,
                    'titre' => $titre,
                    'contenu' => $contenu,
                    'lien' => $lien,
                    'urgente' => $urgente,
                    'lu' => false,
                    'date_envoi' => now(),
                ]);
            } catch (\Throwable $e) {
                // Une notification ne doit jamais faire échouer l'action métier.
                Log::warning('Notification non enregistrée', ['user_id' => $id, 'erreur' => $e->getMessage()]);
            }
        }
    }

    /** Coordinateur en charge de la commande, sinon tous les coordinateurs actifs. */
    public static function coordinateursDe(?Commande $commande = null): array
    {
        if ($commande?->coordinateur_id) {
            return [$commande->coordinateur_id];
        }

        return User::where('type_utilisateur', ROLE_COORDINATEUR)
            ->where('statut_compte', STATUT_COMPTE_ACTIF)
            ->pluck('id')
            ->all();
    }

    /** Commercial humain de la commande (l'agent IA n'a pas d'app à notifier). */
    public static function commercialDe(Commande $commande): ?int
    {
        if (! $commande->commercial_id) {
            return null;
        }

        $estIa = Commercial::where('user_id', $commande->commercial_id)->value('type_commercial') === TYPE_COMMERCIAL_IA;

        return $estIa ? null : $commande->commercial_id;
    }

    /** Prévient la coordination d'une nouvelle commande (une seule fois). */
    public static function nouvelleCommandePourCoordination(Commande $commande): void
    {
        self::sansEchec(fn () => self::nouvelleCommande($commande));
    }

    /** Exécute un envoi de notifications sans jamais faire échouer l'action métier. */
    public static function sansEchec(callable $envoi): void
    {
        try {
            $envoi();
        } catch (\Throwable $e) {
            Log::warning('Notifications non envoyées', ['erreur' => $e->getMessage()]);
        }
    }

    private static function nouvelleCommande(Commande $commande): void
    {
        $commande->forceFill(['coordination_notifiee_le' => now()])->saveQuietly();

        $auteur = $commande->commercial_id ? User::find($commande->commercial_id) : null;
        $par = $auteur ? ' de '.trim($auteur->prenom.' '.$auteur->nom) : '';
        $montant = number_format($commande->montantNet(), 0, ',', ' ');

        self::notifier(
            self::coordinateursDe($commande),
            'commande_nouvelle',
            'Nouvelle commande à valider',
            "Commande n°{$commande->id}{$par} — {$montant} F.",
            "/commande/{$commande->id}",
            urgente: true,
        );
    }

    /**
     * Commandes programmées dont l'heure de transmission est passée : la
     * coordination ne les voyait pas encore, on la prévient maintenant.
     * Appelé quand un coordinateur interroge ses notifications (pas de tâche
     * planifiée sur l'hébergement mutualisé).
     */
    public static function transmettreCommandesProgrammees(): void
    {
        Commande::withoutGlobalScopes()
            ->whereNull('coordination_notifiee_le')
            ->whereNotNull('date_transmission')
            ->where('date_transmission', '<=', now())
            ->limit(50)
            ->get()
            ->each(fn (Commande $c) => self::nouvelleCommandePourCoordination($c));
    }
}
