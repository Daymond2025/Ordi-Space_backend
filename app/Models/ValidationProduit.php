<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValidationProduit extends Model
{
    protected $table = 'validations_produits';
    public $timestamps = false;

    protected $fillable = ['produit_id', 'coordinateur_id', 'decision', 'motif_rejet', 'date_validation'];

    protected function casts(): array
    {
        return ['date_validation' => 'datetime'];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function coordinateur(): BelongsTo
    {
        return $this->belongsTo(Coordinateur::class, 'coordinateur_id', 'user_id');
    }
}
