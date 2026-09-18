<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    // Connexion Client par téléphone : code OTP envoyé par WhatsApp (Twilio).
    // "content_sid" est optionnel — à renseigner seulement si le compte Twilio
    // exige un modèle de message approuvé (contrainte WhatsApp Business API)
    // pour initier une conversation ; sinon un message texte libre est envoyé.
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
        'whatsapp_content_sid' => env('TWILIO_WHATSAPP_CONTENT_SID'),
    ],

    // Encaissement Mobile Money à la livraison (compte marchand OrdiSpace,
    // jamais celui du livreur) — voir App\Services\Wave\WaveCheckoutService.
    'wave' => [
        'base_url' => env('WAVE_API_BASE_URL', 'https://api.wave.com/v1'),
        'api_key' => env('WAVE_API_KEY'),
        'webhook_secret' => env('WAVE_WEBHOOK_SECRET'),
        'min_amount' => (int) env('WAVE_API_MIN_AMOUNT', 100),
        'max_amount' => (int) env('WAVE_API_MAX_AMOUNT', 500000),
        'checkout_success_url' => env('WAVE_CHECKOUT_SUCCESS_URL'),
        'checkout_error_url' => env('WAVE_CHECKOUT_ERROR_URL'),
    ],

];
