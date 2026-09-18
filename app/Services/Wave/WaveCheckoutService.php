<?php

namespace App\Services\Wave;

use App\Models\Commande;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Encaissement Mobile Money à la livraison, directement sur le compte
 * marchand Wave d'OrdiSpace — jamais celui du livreur. Le livreur affiche
 * le QR code généré côté frontend à partir de "wave_launch_url" ; le client
 * scanne avec son propre téléphone. La confirmation réelle du paiement
 * vient uniquement du webhook Wave (voir WaveWebhookController), jamais
 * d'une déclaration du livreur — voir docs.wave.com/checkout et
 * docs.wave.com/webhook.
 */
class WaveCheckoutService
{
    public function creerSession(Commande $commande): array
    {
        $apiKey = config('services.wave.api_key');

        if (! $apiKey) {
            throw ValidationException::withMessages([
                'wave' => ["Le paiement Mobile Money n'est pas configuré pour l'instant."],
            ]);
        }

        // XOF n'admet aucune décimale (voir docs.wave.com/checkout) — nos
        // montants sont déjà des francs CFA entiers, un simple round() suffit.
        $montant = (int) round($commande->montantNet());
        $min = config('services.wave.min_amount');
        $max = config('services.wave.max_amount');

        if ($montant < $min || $montant > $max) {
            throw ValidationException::withMessages([
                'wave' => ["Le montant ({$montant} F) est hors des limites autorisées par Wave ({$min}–{$max} F)."],
            ]);
        }

        try {
            $reponse = Http::withToken($apiKey)
                ->acceptJson()
                ->post(rtrim((string) config('services.wave.base_url'), '/').'/checkout/sessions', [
                    'amount' => (string) $montant,
                    'currency' => 'XOF',
                    'client_reference' => (string) $commande->id,
                    'success_url' => config('services.wave.checkout_success_url'),
                    'error_url' => config('services.wave.checkout_error_url'),
                ])
                ->throw();
        } catch (Throwable $e) {
            Log::error("Échec de création de session Wave pour la commande n°{$commande->id} : {$e->getMessage()}");

            throw ValidationException::withMessages([
                'wave' => ['Impossible de contacter Wave pour le moment, réessayez.'],
            ]);
        }

        return $reponse->json();
    }

    /**
     * Signature HMAC-SHA256 sur "{timestamp}.{corps brut}" — en-tête
     * "Wave-Signature: t=<timestamp>,v1=<signature>". Le corps DOIT rester
     * la chaîne brute reçue, jamais re-sérialisée depuis le JSON parsé (le
     * moindre écart de formatage invalide la comparaison). Fenêtre de 5
     * minutes contre le rejeu, comme documenté par Wave.
     */
    public function verifierSignatureWebhook(string $corpsBrut, ?string $enTeteSignature): bool
    {
        $secret = config('services.wave.webhook_secret');

        if (! $secret || ! $enTeteSignature) {
            return false;
        }

        $parties = [];
        foreach (explode(',', $enTeteSignature) as $partie) {
            [$cle, $valeur] = array_pad(explode('=', $partie, 2), 2, null);
            $parties[$cle] = $valeur;
        }

        $timestamp = $parties['t'] ?? null;
        $signatureRecue = $parties['v1'] ?? null;

        if (! $timestamp || ! $signatureRecue || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $signatureAttendue = hash_hmac('sha256', "{$timestamp}.{$corpsBrut}", $secret);

        return hash_equals($signatureAttendue, $signatureRecue);
    }
}
