<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormuleGarantix extends Model
{
    protected $table = 'formules_garantix';

    protected $fillable = [
        'nom', 'libelle_complet', 'libelle_badge', 'prix_annuel',
        'frequence_interventions', 'description', 'ordre_affichage', 'actif',
    ];

    protected function casts(): array
    {
        return ['prix_annuel' => 'decimal:2', 'actif' => 'boolean'];
    }

    public function prestations(): HasMany
    {
        return $this->hasMany(PrestationGarantix::class, 'formule_garantix_id')->orderBy('ordre_affichage');
    }

    public function abonnements(): HasMany
    {
        return $this->hasMany(AbonnementGarantix::class, 'formule_garantix_id');
    }
}
