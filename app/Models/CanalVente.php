<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanalVente extends Model
{
    protected $table = 'canaux_vente';

    protected $fillable = ['nom_canal'];

    public function commandes(): HasMany
    {
        return $this->hasMany(Commande::class, 'canal_vente_id');
    }
}
