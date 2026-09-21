<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categorie extends Model
{
    protected $table = 'categories';

    protected $fillable = ['nom_categorie', 'description', 'famille', 'groupe', 'libelle', 'ordre_filtre'];

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class, 'categorie_id');
    }
}
