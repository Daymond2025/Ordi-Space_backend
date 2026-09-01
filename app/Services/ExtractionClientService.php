<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paste-parse pour la création rapide de commande depuis une discussion
 * produit (Espace Coordinateur) : extrait nom + téléphone + ville suggérée
 * d'un texte collé (conversation WhatsApp/Facebook). Réutilise le pattern
 * HTTP de AssistantIaService::appellerClaude() en plus simple (un seul tour,
 * sans tools). C'est une aide, jamais un blocage — toute défaillance dégrade
 * silencieusement vers des champs vides que le coordinateur complète à la
 * main. "ville" est purement indicative : la localité réelle (qui conditionne
 * les frais de livraison) est toujours confirmée manuellement côté front,
 * jamais déduite automatiquement de cette suggestion.
 */
class ExtractionClientService
{
    private const CHAMPS_VIDES = ['nom' => null, 'telephone' => null, 'ville' => null];

    public function extraire(string $texte): array
    {
        $cle = config('services.anthropic.key');

        if (! $cle) {
            return self::CHAMPS_VIDES;
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

            return self::CHAMPS_VIDES;
        }

        $texteReponse = collect($reponse->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->first();

        $donnees = json_decode((string) $texteReponse, true);

        if (! is_array($donnees)) {
            Log::error('Extraction client : réponse Claude non JSON', ['reponse' => $texteReponse]);

            return self::CHAMPS_VIDES;
        }

        return [
            'nom' => $donnees['nom'] ?? null,
            'telephone' => $donnees['telephone'] ?? null,
            'ville' => $donnees['ville'] ?? null,
        ];
    }

    private function promptSysteme(): string
    {
        return <<<'PROMPT'
        Tu extrais le nom complet, le numéro de téléphone et la ville/commune de
        livraison suggérée d'un client potentiel à partir d'un message de
        conversation commerciale (WhatsApp, Facebook...). Réponds strictement avec
        un objet JSON de cette forme, sans aucun texte autour : {"nom": string ou
        null, "telephone": string ou null, "ville": string ou null}. N'invente
        rien : si une information est absente du texte, renvoie null pour ce champ.
        "ville" est une simple suggestion textuelle (ex. "Abobo", "Bouaké") — pas
        besoin qu'elle corresponde exactement à un nom officiel.
        PROMPT;
    }
}
