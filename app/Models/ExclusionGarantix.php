<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExclusionGarantix extends Model
{
    protected $table = 'exclusions_garantix';

    protected $fillable = ['libelle', 'ordre_affichage'];
}
