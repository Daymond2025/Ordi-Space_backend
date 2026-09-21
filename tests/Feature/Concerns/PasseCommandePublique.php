<?php

namespace Tests\Feature\Concerns;

use App\Models\AcompteConfirmation;
use App\Models\Commande;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Parcours de l'acheteur sans compte (page_commande) : il ouvre une confirmation payée sur
 * Wave, et la commande n'existe qu'une fois le webhook Wave reçu. Aucun appel réseau réel.
 */
trait PasseCommandePublique
{
    protected function configurerWavePublic(): void
    {
        config([
            'services.wave.api_key' => 'wave_ci_test_fake',
            'services.wave.base_url' => 'https://api.wave.example/v1',
            'services.wave.min_amount' => 100,
            'services.wave.max_amount' => 500000,
            'services.wave.webhook_secret' => 'test-webhook-secret',
            'services.wave.acompte_confirmation' => 200,
            'services.page_commande.url' => 'https://commande.exemple.test',
        ]);
    }

    /** Wave répond toujours avec une nouvelle session (cos_1, cos_2…). */
    protected function fauxWave(): void
    {
        $n = 0;
        Http::fake(['api.wave.example/*' => function () use (&$n) {
            $n++;

            return Http::response(['id' => "cos_{$n}", 'wave_launch_url' => "https://pay.wave.com/c/cos_{$n}"], 200);
        }]);
    }

    /** Ouvre la confirmation (POST /public/confirmations) — la commande n'existe pas encore. */
    protected function ouvrirConfirmation(array $corps): TestResponse
    {
        return $this->postJson('/api/v1/public/confirmations', $corps);
    }

    protected function webhookWave(string $sessionId, string $type = 'checkout.session.completed'): TestResponse
    {
        $corps = json_encode(['id' => 'evt-'.uniqid(), 'type' => $type, 'data' => ['id' => $sessionId]]);
        $horodatage = time();
        $signature = "t={$horodatage},v1=".hash_hmac('sha256', "{$horodatage}.{$corps}", 'test-webhook-secret');

        return $this->call('POST', '/api/v1/webhooks/wave', [], [], [], ['HTTP_Wave-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $corps);
    }

    /** Parcours complet : confirmation ouverte, paiement confirmé par Wave → la commande créée (ou null). */
    protected function passerCommandePublique(array $corps): ?Commande
    {
        $this->ouvrirConfirmation($corps)->assertCreated();
        $acompte = AcompteConfirmation::latest('id')->firstOrFail();
        $this->webhookWave($acompte->wave_checkout_session_id)->assertOk();

        return $acompte->fresh()->commande;
    }
}
