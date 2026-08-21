<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Adresse extends Model
{
    protected $table = 'adresses';

    protected $fillable = ['client_id', 'libelle', 'rue', 'ville', 'pays'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function livraisons(): HasMany
    {
        return $this->hasMany(Livraison::class, 'adresse_id');
    }
}
