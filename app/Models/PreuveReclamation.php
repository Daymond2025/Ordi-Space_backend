<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PreuveReclamation extends Model
{
    protected $table = 'preuves_reclamation';

    protected $fillable = ['reclamation_id', 'fichier'];

    public function reclamation(): BelongsTo
    {
        return $this->belongsTo(Reclamation::class);
    }

    /**
     * En base, fichier est un chemin relatif sur le disque "public" — jamais
     * une URL absolue, pour rester indépendant de APP_URL (même convention
     * que ImageProduit::urlImage()/Message::fichier()).
     */
    protected function fichier(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Storage::disk(IMAGE_PRODUIT_DISQUE)->url($value) : null,
        );
    }

    public function cheminStockage(): string
    {
        return $this->getRawOriginal('fichier');
    }
}
