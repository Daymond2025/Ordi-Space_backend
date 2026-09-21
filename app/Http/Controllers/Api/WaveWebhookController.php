<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcompteConfirmation;
use App\Models\Paiement;
use App\Services\AcompteConfirmationService;
use App\Services\Wave\WaveCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Réception des événements Wave (checkout.session.completed /
 * checkout.session.payment_failed) — endpoint public (voir routes/api.php,
 * hors auth:sanctum), sécurisé uniquement par la signature "Wave-Signature".
 * Seule source de vérité pour confirmer un paiement Mobile Money : jamais le
 * livreur qui déclare lui-même avoir été payé.
 */
class WaveWebhookController extends Controller
{
    public function __construct(private readonly WaveCheckoutService $wave, private readonly AcompteConfirmationService $acomptes) {}

    public function handle(Request $request): JsonResponse
    {
        // getContent() : le corps brut exact, indispensable à la vérification
        // de signature — jamais $request->input() (JSON déjà re-parsé) ici.
        $corpsBrut = $request->getContent();

        abort_unless(
            $this->wave->verifierSignatureWebhook($corpsBrut, $request->header('Wave-Signature')),
            401
        );

        $type = $request->input('type');
        $sessionId = $request->input('data.id');

        if (! $sessionId) {
            return $this->success(['message' => 'Ignoré (pas de session).']);
        }

        // Paiement de confirmation d'une commande de la page acheteur (pas encore de commande).
        if ($acompte = AcompteConfirmation::where('wave_checkout_session_id', $sessionId)->first()) {
            if ($type === 'checkout.session.completed') {
                $this->acomptes->confirmer($acompte);
            } elseif ($type === 'checkout.session.payment_failed') {
                $this->acomptes->echouer($acompte);
            }

            return $this->success(['message' => 'OK']);
        }

        $paiement = Paiement::where('wave_checkout_session_id', $sessionId)->first();

        if (! $paiement) {
            Log::warning("Webhook Wave reçu pour une session inconnue : {$sessionId}");

            return $this->success(['message' => 'Session inconnue.']);
        }

        if ($type === 'checkout.session.completed') {
            $paiement->update(['statut_paiement' => STATUT_PAIEMENT_CONFIRME, 'date_paiement' => now()]);
            // Idempotent (voir Livraison::marquerLivree()) — un webhook Wave
            // rejoué ne doit pas régénérer garantie/crédits une seconde fois.
            $paiement->commande->livraison?->marquerLivree();
        } elseif ($type === 'checkout.session.payment_failed') {
            $paiement->update(['statut_paiement' => STATUT_PAIEMENT_ECHOUE]);
        } else {
            Log::info("Webhook Wave — type d'événement ignoré : {$type}");
        }

        return $this->success(['message' => 'OK']);
    }
}
