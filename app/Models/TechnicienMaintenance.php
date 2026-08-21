<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicienMaintenance extends Model
{
    protected $table = 'techniciens_maintenance';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id', 'specialite'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class, 'technicien_id', 'user_id');
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(Intervention::class, 'technicien_id', 'user_id');
    }
}
