<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Localite extends Model
{
    protected $table = 'localites';

    protected $fillable = ['nom', 'type'];

    public function fraisLivraison(): HasMany
    {
        return $this->hasMany(FraisLivraisonProduit::class, 'localite_id');
    }

    public function adresses(): HasMany
    {
        return $this->hasMany(Adresse::class, 'localite_id');
    }
}
