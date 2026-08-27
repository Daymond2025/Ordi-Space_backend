<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paste-parse pour la création rapide de commande depuis une discussion
 * produit (Espace Coordinateur) : extrait nom + téléphone d'un texte collé
 * (conversation WhatsApp/Facebook). Réutilise le pattern HTTP de
 * AssistantIaService::appellerClaude() en plus simple (un seul tour, sans
 * tools). C'est une aide, jamais un blocage — toute défaillance dégrade
 * silencieusement vers des champs vides que le coordinateur complète à la main.
 */
class ExtractionClientService
{
    public function extraire(string $texte): array
    {
        $cle = config('services.anthropic.key');

        if (! $cle) {
            return ['nom' => null, 'telephone' => null];
        }

        $reponse = Http::withHeaders([
            'x-api-key' => $cle,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(15)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 256,
            'system' => $this->promptSysteme(),
            'messages' => [['role' => 'user', 'content' => $texte]],
        ]);

        if ($reponse->failed()) {
            Log::error('Extraction client : échec de l\'appel à Claude', [
                'status' => $reponse->status(),
                'body' => $reponse->body(),
            ]);

            return ['nom' => null, 'telephone' => null];
        }

        $texteReponse = collect($reponse->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->first();

        $donnees = json_decode((string) $texteReponse, true);

        if (! is_array($donnees)) {
            Log::error('Extraction client : réponse Claude non JSON', ['reponse' => $texteReponse]);

            return ['nom' => null, 'telephone' => null];
        }

        return [
            'nom' => $donnees['nom'] ?? null,
            'telephone' => $donnees['telephone'] ?? null,
        ];
    }

    private function promptSysteme(): string
    {
        return <<<'PROMPT'
        Tu extrais uniquement le nom complet et le numéro de téléphone d'un client
        potentiel à partir d'un message de conversation commerciale (WhatsApp,
        Facebook...). Réponds strictement avec un objet JSON de cette forme, sans
        aucun texte autour : {"nom": string ou null, "telephone": string ou null}.
        N'invente rien : si une information est absente du texte, renvoie null pour
        ce champ.
        PROMPT;
    }
}
