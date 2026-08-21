<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressionTutoriel extends Model
{
    protected $table = 'progressions_tutoriels';

    protected $fillable = ['client_id', 'tutoriel_id', 'statut', 'date_vue'];

    protected function casts(): array
    {
        return ['date_vue' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function tutoriel(): BelongsTo
    {
        return $this->belongsTo(Tutoriel::class);
    }
}
