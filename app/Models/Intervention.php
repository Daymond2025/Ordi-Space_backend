<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Intervention extends Model
{
    protected $table = 'interventions';

    protected $fillable = [
        'rendez_vous_id', 'technicien_id', 'diagnostic', 'reparation_effectuee',
        'statut_intervention', 'cout', 'date_intervention',
        'problemes', 'probleme_identifie', 'cause_panne', 'niveau_gravite', 'duree_estimee_minutes',
        'prestations', 'pieces_remplacees', 'date_fin', 'duree_reelle_minutes', 'note_fin',
    ];

    protected function casts(): array
    {
        return [
            'cout' => 'decimal:2',
            'date_intervention' => 'datetime',
            'date_fin' => 'datetime',
            'problemes' => 'array',
            'prestations' => 'array',
            'pieces_remplacees' => 'array',
        ];
    }

    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class, 'rendez_vous_id');
    }

    public function technicien(): BelongsTo
    {
        return $this->belongsTo(TechnicienMaintenance::class, 'technicien_id', 'user_id');
    }
}
