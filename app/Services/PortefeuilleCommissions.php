<?php

namespace App\Services;

use App\Models\DemandeRetrait;
use App\Models\User;
use App\Models\VenteBoutique;

/**
 * Portefeuille de commissions d'un livreur (Boutique) — jamais stocké, toujours
 * recalculé depuis ses ventes et ses demandes de retrait : aucune dérive
 * possible entre un solde enregistré et la réalité.
 *
 *  - gain total cumulé : commissions des ventes dont la commande a été validée
 *    par l'Admin/Coordinateur et n'a pas été annulée (règle de la Boutique :
 *    la commission est acquise à la validation, pas à la livraison) ;
 *  - en attente de validation : commissions des ventes dont la commande n'a
 *    pas encore été validée ;
 *  - retraits engagés : demandes en attente ou déjà payées, déduites du solde ;
 *  - disponible : gain total − retraits engagés (jamais négatif — une commande
 *    annulée après un retrait ne fait pas devoir de l'argent au livreur ici).
 *    Une demande refusée ou annulée est simplement restituée.
 */
class PortefeuilleCommissions
{
    /** @return array{disponible: float, gain_total: float, en_attente_validation: float, retrait_en_cours: float, retire: float} */
    public static function resume(User $user): array
    {
        $ventes = VenteBoutique::where('livreur_id', $user->id);

        $commissionsDes = fn (array $statutsCommande) => (float) (clone $ventes)
            ->whereHas('commande', fn ($q) => $q->whereIn('statut_commande', $statutsCommande))
            ->sum('commission');

        $retraits = fn (string $statut) => (float) DemandeRetrait::where('user_id', $user->id)->where('statut', $statut)->sum('montant');

        $gainTotal = $commissionsDes(VenteBoutique::statutsCommandeAcquerantCommission());
        $enCours = $retraits(STATUT_RETRAIT_EN_ATTENTE);
        $retire = $retraits(STATUT_RETRAIT_VALIDE);

        return [
            'disponible' => max(0.0, $gainTotal - $enCours - $retire),
            'gain_total' => $gainTotal,
            'en_attente_validation' => $commissionsDes(VenteBoutique::statutsCommandeParStatutAffiche()['en_attente']),
            'retrait_en_cours' => $enCours,
            'retire' => $retire,
        ];
    }
}
