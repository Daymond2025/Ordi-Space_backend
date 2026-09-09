<?php

namespace App\Http\Requests\Message;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Le type précis (et donc le plafond de poids applicable) est déterminé
     * après coup par MessageController::inferTypeEtStocker() à partir de
     * l'extension — ici on ne valide que "texte XOR fichier, d'un type
     * globalement pris en charge". "type" n'est utile que pour distinguer
     * une note vocale d'un simple audio (même mimes, sinon indiscernables).
     */
    public function rules(): array
    {
        $tousMimes = implode(',', array_unique(array_merge(
            explode(',', IMAGE_MIMES_AUTORISES),
            explode(',', AUDIO_MIMES_AUTORISES),
            explode(',', VIDEO_MIMES_AUTORISES),
            explode(',', DOCUMENT_MIMES_AUTORISES),
        )));

        return [
            'contenu' => ['required_without:fichier', 'prohibits:fichier', 'string', 'max:'.MESSAGE_CONTENU_MAX_LONGUEUR],
            // L'extension déclarée par le client reste le seul moyen de
            // choisir la CATÉGORIE (image/audio/vidéo/document) — nécessaire
            // pour `webm`, que le registre mimetype de Symfony ne mappe qu'à
            // `video/webm` alors que MediaRecorder produit typiquement
            // `audio/webm` côté navigateur (la règle native `mimes:` le
            // rejetterait). Mais le CONTENU réel (getMimeType(), sniffé via
            // finfo, pas fourni par le client) doit ensuite correspondre à
            // cette catégorie — un fichier renommé avec une extension qui ne
            // correspond pas à son contenu réel est rejeté.
            'fichier' => [
                'required_without:contenu',
                'file',
                function ($attribute, $value, $fail) use ($tousMimes) {
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (! in_array($extension, explode(',', $tousMimes), true)) {
                        $fail("Le champ {$attribute} doit être un fichier de type : {$tousMimes}.");
                        return;
                    }

                    $mime = $value->getMimeType();
                    $prefixesAttendus = match (true) {
                        in_array($extension, explode(',', IMAGE_MIMES_AUTORISES), true) => ['image/'],
                        $extension === 'webm' => ['audio/', 'video/'],
                        in_array($extension, explode(',', AUDIO_MIMES_AUTORISES), true) => ['audio/'],
                        in_array($extension, explode(',', VIDEO_MIMES_AUTORISES), true) => ['video/'],
                        in_array($extension, explode(',', DOCUMENT_MIMES_AUTORISES), true) => ['application/'],
                        default => [],
                    };

                    $correspond = empty($prefixesAttendus)
                        || collect($prefixesAttendus)->contains(fn ($prefixe) => str_starts_with($mime, $prefixe));

                    if (! $correspond) {
                        $fail("Le fichier ne correspond pas au type déclaré par son extension ({$extension}).");
                    }
                },
            ],
            'type' => ['nullable', Rule::in([TYPE_MESSAGE_NOTE_VOCALE])],
        ];
    }
}
