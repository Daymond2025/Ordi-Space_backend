<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coordinateur extends Model
{
    protected $table = 'coordinateurs';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function commandesValidees(): HasMany
    {
        return $this->hasMany(Commande::class, 'coordinateur_id', 'user_id');
    }

    public function validationsProduits(): HasMany
    {
        return $this->hasMany(ValidationProduit::class, 'coordinateur_id', 'user_id');
    }
}
