<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $table = 'messages';

    public $timestamps = false;

    protected $fillable = ['produit_id', 'commande_id', 'auteur_id', 'type', 'contenu', 'fichier', 'donnees', 'date_envoi'];

    protected function casts(): array
    {
        return ['date_envoi' => 'datetime', 'donnees' => 'array'];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
