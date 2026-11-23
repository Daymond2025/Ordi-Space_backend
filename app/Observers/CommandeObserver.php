<?php

namespace App\Observers;

use App\Models\Commande;
use App\Services\NotificationService as N;
use Illuminate\Support\Facades\DB;

/**
 * Notifications liées au cycle de vie d'une commande, quel que soit le chemin
 * qui la crée ou la fait avancer (commercial, client, lien de vente, livreur,
 * coordinateur). Le changement est capturé tout de suite (une commande est
 * parfois ré-enregistrée dans la même transaction, ce qui effacerait
 * wasChanged), l'envoi n'a lieu qu'après commit (DB::afterCommit), jamais
 * pour une transaction annulée.
 */
class CommandeObserver
{
    public function created(Commande $commande): void
    {
        if ($commande->statut_commande !== STATUT_COMMANDE_EN_ATTENTE) {
            return;
        }

        // Commande programmée : la coordination sera prévenue à sa date de
        // transmission (NotificationService::transmettreCommandesProgrammees).
        if ($commande->date_transmission && $commande->date_transmission->isFuture()) {
            return;
        }

        DB::afterCommit(fn () => N::nouvelleCommandePourCoordination($commande));
    }

    public function updated(Commande $commande): void
    {
        if (! $commande->wasChanged('statut_commande')) {
            return;
        }

        $statut = $commande->statut_commande;
        DB::afterCommit(fn () => N::sansEchec(fn () => $this->notifierStatut($commande, $statut)));
    }

    private function notifierStatut(Commande $commande, string $statut): void
    {
        $id = $commande->id;
        $lien = "/commande/{$id}";
        $commercial = N::commercialDe($commande);
        $client = $commande->client_id;

        switch ($statut) {
            case STATUT_COMMANDE_VALIDEE:
                N::notifier($commercial, 'commande_validee', 'Commande validée', "La commande n°{$id} a été validée par la coordination.", $lien);
                N::notifier($client, 'commande_validee', 'Commande confirmée', "Votre commande n°{$id} est confirmée. Nous préparons sa livraison.", "/mes-commandes/{$id}");
                break;

            case STATUT_COMMANDE_EN_LIVRAISON:
                N::notifier($commercial, 'commande_en_livraison', 'Commande en livraison', "Un livreur est en route pour la commande n°{$id}.", $lien);
                N::notifier($client, 'commande_en_livraison', 'Votre commande arrive', "Un livreur est en route avec votre commande n°{$id}.", "/mes-commandes/{$id}");
                break;

            case STATUT_COMMANDE_LIVREE:
                N::notifier($commercial, 'commande_livree', 'Commande livrée', "La commande n°{$id} a été livrée.", $lien);
                N::notifier($client, 'commande_livree', 'Commande livrée', "Votre commande n°{$id} a été livrée. Merci pour votre confiance !", "/mes-commandes/{$id}");
                N::notifier(N::coordinateursDe($commande), 'commande_livree', 'Commande livrée', "La commande n°{$id} a été livrée.", $lien);
                break;

            case STATUT_COMMANDE_ANNULEE:
                N::notifier($commercial, 'commande_annulee', 'Commande annulée', "La commande n°{$id} a été annulée.", $lien, urgente: true);
                N::notifier($client, 'commande_annulee', 'Commande annulée', "Votre commande n°{$id} a été annulée. Contactez-nous pour toute question.", "/mes-commandes/{$id}");
                break;

            case STATUT_COMMANDE_REPORTEE:
                N::notifier($commercial, 'commande_reportee', 'Commande reportée', "La livraison de la commande n°{$id} est reportée.", $lien);
                N::notifier($client, 'commande_reportee', 'Livraison reportée', "La livraison de votre commande n°{$id} est reportée. Nous vous recontactons.", "/mes-commandes/{$id}");
                break;

            case STATUT_COMMANDE_CLIENT_INJOIGNABLE:
            case STATUT_COMMANDE_NUMERO_INCORRECT:
                $motif = $statut === STATUT_COMMANDE_CLIENT_INJOIGNABLE ? 'client injoignable' : 'numéro incorrect';
                N::notifier(
                    array_filter([...N::coordinateursDe($commande), $commercial]),
                    'commande_probleme',
                    'Problème sur une commande',
                    "Commande n°{$id} : {$motif}. Une action est nécessaire.",
                    $lien,
                    urgente: true,
                );
                break;
        }
    }
}
