<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reclamation extends Model
{
    protected $table = 'reclamations';

    protected $fillable = [
        'client_id', 'commande_id', 'sujet', 'description', 'statut',
        'reponse_admin', 'date_reclamation', 'date_traitement',
    ];

    protected function casts(): array
    {
        return [
            'date_reclamation' => 'datetime',
            'date_traitement' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }
}
