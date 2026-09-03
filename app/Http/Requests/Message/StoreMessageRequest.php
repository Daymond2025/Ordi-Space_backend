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
            // Validation par extension plutôt que par la règle `mimes:` de
            // Laravel (qui passe par le registre mimetype de Symfony) : ce
            // registre ne mappe `webm` qu'à `video/webm`, alors que
            // MediaRecorder produit typiquement `audio/webm` côté navigateur
            // — la règle native rejetterait alors un vrai message vocal.
            // MessageController::inferTypeEtStocker() ne regarde de toute
            // façon que l'extension, donc cette validation reste cohérente.
            'fichier' => [
                'required_without:contenu',
                'file',
                function ($attribute, $value, $fail) use ($tousMimes) {
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (! in_array($extension, explode(',', $tousMimes), true)) {
                        $fail("Le champ {$attribute} doit être un fichier de type : {$tousMimes}.");
                    }
                },
            ],
            'type' => ['nullable', Rule::in([TYPE_MESSAGE_NOTE_VOCALE])],
        ];
    }
}
