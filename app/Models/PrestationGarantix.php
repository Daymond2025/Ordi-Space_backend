<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrestationGarantix extends Model
{
    protected $table = 'prestations_garantix';

    protected $fillable = ['formule_garantix_id', 'libelle', 'ordre_affichage'];

    public function formule(): BelongsTo
    {
        return $this->belongsTo(FormuleGarantix::class, 'formule_garantix_id');
    }
}
