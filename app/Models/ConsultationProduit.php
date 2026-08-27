<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationProduit extends Model
{
    protected $table = 'consultations_produits';
    public $timestamps = false;

    protected $fillable = ['user_id', 'produit_id', 'consulte_le'];

    protected function casts(): array
    {
        return ['consulte_le' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
