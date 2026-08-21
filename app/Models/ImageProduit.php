<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ImageProduit extends Model
{
    protected $table = 'images_produits';

    protected $fillable = ['produit_id', 'url_image', 'ordre_affichage'];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    /**
     * En base, url_image est un chemin relatif sur le disque "public"
     * (ex. "produits/abc123.jpg") — jamais une URL absolue, pour rester
     * indépendant de APP_URL. On ne calcule l'URL complète qu'à la lecture.
     */
    protected function urlImage(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Storage::disk(IMAGE_PRODUIT_DISQUE)->url($value) : null,
        );
    }

    public function cheminStockage(): string
    {
        return $this->getRawOriginal('url_image');
    }
}
