<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fournisseur extends Model
{
    protected $table = 'fournisseurs';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id', 'nom_entreprise', 'adresse_entreprise', 'contact_pro'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class, 'fournisseur_id', 'user_id');
    }

    public function retours(): HasMany
    {
        return $this->hasMany(Retour::class, 'fournisseur_id', 'user_id');
    }
}
