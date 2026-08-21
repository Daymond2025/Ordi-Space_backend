<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class QuestionFrequente extends Model
{
    protected $table = 'questions_frequentes';

    protected $fillable = ['question', 'reponse', 'fichier_audio', 'statut', 'ordre_affichage'];

    /**
     * Même principe que Tutoriel::imageCouverture : chemin relatif en base,
     * URL calculée à la lecture — indépendant de APP_URL.
     */
    protected function fichierAudio(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Storage::disk(IMAGE_PRODUIT_DISQUE)->url($value) : null,
        );
    }

    public function cheminStockageAudio(): ?string
    {
        return $this->getRawOriginal('fichier_audio');
    }

    public function estPublie(): bool
    {
        return $this->statut === STATUT_QUESTION_PUBLIE;
    }
}
