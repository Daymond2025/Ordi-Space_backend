<?php

namespace App\Services;

use App\Models\AcompteConfirmation;
use App\Models\JournalAudit;
use App\Models\LienAffilie;
use App\Models\Produit;
use App\Models\User;
use App\Services\Wave\WaveCheckoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Paiement de confirmation d'une commande de la page acheteur.
 *
 * L'acheteur règle un petit montant sur Wave (200 F par défaut, jamais remboursé) pour
 * prouver qu'il achètera vraiment ; le reliquat est payé à la livraison. Rien n'est créé côté
 * commandes avant ce paiement : initier() enregistre une "intention" (de quoi créer la
 * commande) et ouvre la session Wave ; c'est le webhook Wave, seule source de vérité, qui
 * appelle confirmer() — la commande naît alors, comme si l'acheteur venait de la passer.
 */
class AcompteConfirmationService
{
    public function __construct(
        private readonly WaveCheckoutService $wave,
        private readonly CommandePubliqueService $commandes,
    ) {}

    /**
     * @param array{nom: string, prenom?: ?string, telephone: string, localite_id: int, adresse: string, notes?: ?string} $acheteur
     */
    public function initier(User $vendeur, Produit $produit, int $quantite, array $acheteur, string $source, ?LienAffilie $lien): AcompteConfirmation
    {
        // Refuser avant de faire payer : une commande impossible ne doit pas coûter 200 F.
        $this->commandes->verifierDisponibilite($produit, $quantite, $acheteur['localite_id']);

        $acompte = AcompteConfirmation::create([
            'token' => Str::random(40),
            'montant' => (int) config('services.wave.acompte_confirmation'),
            'statut' => STATUT_PAIEMENT_EN_ATTENTE,
            'donnees' => [
                'vendeur_id' => $vendeur->id,
                'produit_id' => $produit->id,
                'lien_id' => $lien?->id,
                'source' => $source,
                'quantite' => $quantite,
                'acheteur' => $acheteur,
            ],
        ]);

        try {
            $session = $this->wave->creerSessionAcompte($acompte, $this->urlRetour($acompte));
        } catch (Throwable $e) {
            // Pas de session Wave = rien à payer : on ne garde pas une intention morte.
            $acompte->delete();
            throw $e;
        }

        $acompte->update([
            'wave_checkout_session_id' => $session['id'] ?? null,
            'wave_launch_url' => $session['wave_launch_url'] ?? null,
        ]);

        return $acompte;
    }

    /** Page de l'acheteur où Wave le ramène (paiement réussi ou non) — elle lit le statut réel. */
    public function urlRetour(AcompteConfirmation $acompte): string
    {
        return rtrim((string) config('services.page_commande.url'), '/')."/boutique/confirmation/{$acompte->token}";
    }

    /**
     * Paiement confirmé par Wave : marque l'acompte payé (une seule fois, même si le webhook
     * est rejoué) puis crée la commande. Si la commande est devenue impossible entre-temps
     * (stock épuisé, produit retiré), l'argent est bien reçu : l'acompte reste "payé" avec
     * l'erreur, en anomalie visible de l'Admin, qui reprend contact avec l'acheteur.
     */
    public function confirmer(AcompteConfirmation $acompte): void
    {
        $premiereFois = DB::transaction(function () use ($acompte) {
            $courant = AcompteConfirmation::whereKey($acompte->id)->lockForUpdate()->first();
            if ($courant->estPaye()) {
                return false;
            }
            $courant->update(['statut' => STATUT_PAIEMENT_CONFIRME, 'date_paiement' => now()]);

            return true;
        });

        if (! $premiereFois) {
            return;
        }

        $acompte->refresh();
        $donnees = $acompte->donnees;

        try {
            $commande = $this->commandes->creer(
                User::findOrFail($donnees['vendeur_id']),
                Produit::findOrFail($donnees['produit_id']),
                (int) $donnees['quantite'],
                $donnees['acheteur'],
                $donnees['source'],
                isset($donnees['lien_id']) ? LienAffilie::find($donnees['lien_id']) : null,
            );
        } catch (Throwable $e) {
            $message = $e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage();
            Log::error("Confirmation n°{$acompte->id} payée mais commande impossible : {$message}");
            $acompte->update(['erreur' => mb_substr($message, 0, 500)]);

            return;
        }

        $acompte->update(['commande_id' => $commande->id]);

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_ACOMPTE_CONFIRMATION_PAYE,
            'commande',
            "Confirmation de {$acompte->montant} F payée via Wave (non remboursable) — le reliquat du client, {$commande->reliquat()} F, se paie à la livraison.",
            commandeId: $commande->id,
            acteurId: $commande->client_id,
        );
    }

    /** Wave signale un paiement non abouti : l'intention reste consultable, rien n'est créé. */
    public function echouer(AcompteConfirmation $acompte): void
    {
        if ($acompte->statut === STATUT_PAIEMENT_EN_ATTENTE) {
            $acompte->update(['statut' => STATUT_PAIEMENT_ECHOUE]);
        }
    }
}
