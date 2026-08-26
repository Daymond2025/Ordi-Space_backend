<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Log;
use Throwable;
use Twilio\Rest\Client;

class WhatsappOtpSender
{
    /**
     * Envoie le code OTP par WhatsApp via Twilio. Retourne false (sans lever
     * d'exception) si Twilio n'est pas configuré ou si l'envoi échoue — le
     * code reste valide côté backend dans tous les cas, l'appelant décide du
     * mode de secours (cf. User::emettreCodeOtp() et ses appelants).
     */
    public function envoyer(string $telephoneE164, string $code): bool
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.auth_token');
        $from = config('services.twilio.whatsapp_from');

        if (! $sid || ! $token || ! $from) {
            Log::info("[OTP WhatsApp — Twilio non configuré] Code pour {$telephoneE164} : {$code}");

            return false;
        }

        $params = ['from' => $from];
        $contentSid = config('services.twilio.whatsapp_content_sid');

        if ($contentSid) {
            $params['contentSid'] = $contentSid;
            $params['contentVariables'] = json_encode(['1' => $code]);
        } else {
            $params['body'] = "Votre code de connexion OrdiSpace : {$code} (valable ".OTP_EXPIRATION_MINUTES." minutes).";
        }

        try {
            (new Client($sid, $token))->messages->create("whatsapp:{$telephoneE164}", $params);

            return true;
        } catch (Throwable $e) {
            Log::warning("Échec d'envoi de l'OTP WhatsApp via Twilio pour {$telephoneE164} : {$e->getMessage()}");

            return false;
        }
    }
}
