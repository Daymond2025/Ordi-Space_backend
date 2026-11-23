<?php

namespace App\Observers;

use App\Models\Commande;
use App\Models\Message;
use App\Models\User;
use App\Services\NotificationService as N;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Nouveau message dans une discussion (commande ou produit) : on prévient
 * l'autre côté — la coordination quand c'est un commercial qui écrit, le
 * commercial quand c'est la coordination.
 */
class MessageObserver implements ShouldHandleEventsAfterCommit
{
    /** Messages générés par le système : déjà couverts par d'autres notifications. */
    private const TYPES_IGNORES = [TYPE_MESSAGE_RAPPORT, TYPE_MESSAGE_COMMANDE_CREEE];

    public function created(Message $message): void
    {
        N::sansEchec(fn () => $this->notifier($message));
    }

    private function notifier(Message $message): void
    {
        if (in_array($message->type, self::TYPES_IGNORES, true) || ! $message->auteur_id) {
            return;
        }

        $auteur = User::find($message->auteur_id);
        if (! $auteur) {
            return;
        }

        $nom = trim($auteur->prenom.' '.$auteur->nom) ?: 'Quelqu\'un';
        $apercu = $this->apercu($message);
        $estCoordination = in_array($auteur->type_utilisateur, [ROLE_COORDINATEUR, ROLE_ADMINISTRATEUR], true);

        if ($message->commande_id) {
            $commande = Commande::withoutGlobalScopes()->find($message->commande_id);
            if (! $commande) {
                return;
            }

            $destinataires = $estCoordination
                ? [N::commercialDe($commande)]
                : N::coordinateursDe($commande);

            N::notifier(
                array_filter($destinataires),
                'message_commande',
                "Message — commande n°{$commande->id}",
                "{$nom} : {$apercu}",
                "/commande/{$commande->id}",
            );

            return;
        }

        if ($message->produit_id) {
            // Côté commercial : seuls ceux qui participent déjà à cette discussion.
            $destinataires = $estCoordination
                ? Message::where('produit_id', $message->produit_id)
                    ->whereHas('auteur', fn ($q) => $q->where('type_utilisateur', ROLE_COMMERCIAL))
                    ->distinct()
                    ->pluck('auteur_id')
                    ->all()
                : N::coordinateursDe();

            N::notifier(
                $destinataires,
                'message_produit',
                'Nouveau message produit',
                "{$nom} : {$apercu}",
                "/produit/{$message->produit_id}",
            );
        }
    }

    private function apercu(Message $message): string
    {
        return match ($message->type) {
            TYPE_MESSAGE_AUDIO, TYPE_MESSAGE_NOTE_VOCALE => '🎤 Note vocale',
            TYPE_MESSAGE_IMAGE => '📷 Photo',
            TYPE_MESSAGE_VIDEO => '🎬 Vidéo',
            TYPE_MESSAGE_DOCUMENT => '📄 Document',
            TYPE_MESSAGE_PROPOSITION_PRIX => '💰 Proposition de prix',
            default => mb_strimwidth((string) $message->contenu, 0, 120, '…'),
        };
    }
}
