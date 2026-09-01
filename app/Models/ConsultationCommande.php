<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationCommande extends Model
{
    protected $table = 'consultations_commandes';
    public $timestamps = false;

    protected $fillable = ['user_id', 'commande_id', 'consulte_le'];

    protected function casts(): array
    {
        return ['consulte_le' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }
}
