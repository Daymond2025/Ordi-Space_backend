<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commercial extends Model
{
    protected $table = 'commerciaux';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id', 'type_commercial', 'matricule', 'nom_modele_ia'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(Commande::class, 'commercial_id', 'user_id');
    }

    public function estAgentIa(): bool
    {
        return $this->type_commercial === TYPE_COMMERCIAL_IA;
    }
}
