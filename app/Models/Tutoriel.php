<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Tutoriel extends Model
{
    protected $table = 'tutoriels';

    protected $fillable = ['titre', 'type', 'url_video', 'contenu', 'image_couverture', 'statut', 'date_publication'];

    protected $appends = ['id_video_youtube'];

    protected function casts(): array
    {
        return ['date_publication' => 'datetime'];
    }

    /**
     * Extrait l'identifiant vidéo à partir d'un lien YouTube complet (watch,
     * youtu.be, embed, shorts) : évite de dupliquer ce parsing dans chaque
     * client (mobile + admin) qui doit intégrer le lecteur.
     */
    protected function idVideoYoutube(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->url_video) {
                    return null;
                }

                preg_match(
                    '/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/',
                    $this->url_video,
                    $correspondances
                );

                return $correspondances[1] ?? null;
            },
        );
    }

    /**
     * Même principe que ImageProduit : chemin relatif en base, URL calculée
     * à la lecture — indépendant de APP_URL.
     */
    protected function imageCouverture(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Storage::disk(IMAGE_PRODUIT_DISQUE)->url($value) : null,
        );
    }

    public function cheminStockage(): ?string
    {
        return $this->getRawOriginal('image_couverture');
    }

    public function estPublie(): bool
    {
        return $this->statut === STATUT_TUTORIEL_PUBLIE;
    }
}
