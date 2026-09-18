<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Livreur extends Model
{
    protected $table = 'livreurs';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = [
        'user_id', 'type_vehicule', 'zone_couverture', 'disponible',
        'photo_permis', 'photo_cni', 'photo_carte_grise',
    ];

    protected function casts(): array
    {
        return ['disponible' => 'boolean'];
    }

    /**
     * En base, un chemin relatif sur le disque "public" — jamais une URL
     * absolue (même convention que User::photo()). Exigées dès l'inscription
     * (voir AuthController::register()), consultables sur la fiche livreur
     * (LivreurController::show()) pour identifier le livreur en cas de vol.
     */
    protected function photoPermis(): Attribute
    {
        return Attribute::make(get: fn (?string $value) => $value ? Storage::disk(IMAGE_PRODUIT_DISQUE)->url($value) : null);
    }

    protected function photoCni(): Attribute
    {
        return Attribute::make(get: fn (?string $value) => $value ? Storage::disk(IMAGE_PRODUIT_DISQUE)->url($value) : null);
    }

    protected function photoCarteGrise(): Attribute
    {
        return Attribute::make(get: fn (?string $value) => $value ? Storage::disk(IMAGE_PRODUIT_DISQUE)->url($value) : null);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function livraisons(): HasMany
    {
        return $this->hasMany(Livraison::class, 'livreur_id', 'user_id');
    }

    public function paiementsEncaisses(): HasMany
    {
        return $this->hasMany(Paiement::class, 'livreur_id', 'user_id');
    }
}
