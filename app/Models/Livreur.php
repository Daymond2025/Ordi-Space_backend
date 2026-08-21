<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Livreur extends Model
{
    protected $table = 'livreurs';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id', 'type_vehicule', 'zone_couverture'];

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
