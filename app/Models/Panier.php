<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Panier extends Model
{
    protected $table = 'paniers';

    protected $fillable = ['client_id'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LignePanier::class, 'panier_id');
    }
}
