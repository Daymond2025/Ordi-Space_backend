<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandeSav extends Model
{
    protected $table = 'demandes_sav';

    protected $fillable = ['client_id', 'garantie_id', 'description_probleme', 'statut_demande', 'date_demande'];

    protected function casts(): array
    {
        return ['date_demande' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function garantie(): BelongsTo
    {
        return $this->belongsTo(Garantie::class, 'garantie_id');
    }

    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class, 'demande_sav_id');
    }
}
