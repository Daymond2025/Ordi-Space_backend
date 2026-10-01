<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreferenceAccueilProduit extends Model
{
    protected $table = 'preferences_accueil_produits';
    public $timestamps = false;

    protected $fillable = ['user_id', 'produit_id', 'epingle', 'masque_depuis'];

    protected function casts(): array
    {
        return ['epingle' => 'boolean', 'masque_depuis' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
