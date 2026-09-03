<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Message extends Model
{
    protected $table = 'messages';

    public $timestamps = false;

    protected $fillable = [
        'produit_id', 'commande_id', 'auteur_id', 'type', 'contenu', 'fichier', 'donnees',
        'date_envoi', 'est_negociation_prix',
    ];

    protected function casts(): array
    {
        return ['date_envoi' => 'datetime', 'donnees' => 'array', 'est_negociation_prix' => 'boolean'];
    }

    /**
     * En base, `fichier` est un chemin relatif sur le disque "public" (ex.
     * "messages/audio/abc123.webm"), jamais une URL absolue — même pattern
     * que ImageProduit::urlImage(), l'URL complète n'est calculée qu'à la
     * lecture, pour rester indépendant de APP_URL.
     */
    protected function fichier(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Storage::disk(IMAGE_PRODUIT_DISQUE)->url($value) : null,
        );
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
