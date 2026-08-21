<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Livraison extends Model
{
    protected $table = 'livraisons';

    protected $fillable = [
        'commande_id', 'livreur_id', 'adresse_id', 'date_prise_en_charge',
        'date_livraison_prevue', 'date_livraison_effective', 'statut_livraison', 'preuve_livraison',
    ];

    protected function casts(): array
    {
        return [
            'date_prise_en_charge' => 'datetime',
            'date_livraison_prevue' => 'datetime',
            'date_livraison_effective' => 'datetime',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class, 'livreur_id', 'user_id');
    }

    public function adresse(): BelongsTo
    {
        return $this->belongsTo(Adresse::class, 'adresse_id');
    }
}
