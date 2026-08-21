<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RendezVous extends Model
{
    protected $table = 'rendez_vous';

    protected $fillable = ['demande_sav_id', 'technicien_id', 'date_rdv', 'lieu'];

    protected function casts(): array
    {
        return ['date_rdv' => 'datetime'];
    }

    public function demandeSav(): BelongsTo
    {
        return $this->belongsTo(DemandeSav::class, 'demande_sav_id');
    }

    public function technicien(): BelongsTo
    {
        return $this->belongsTo(TechnicienMaintenance::class, 'technicien_id', 'user_id');
    }

    public function intervention(): HasOne
    {
        return $this->hasOne(Intervention::class, 'rendez_vous_id');
    }
}
