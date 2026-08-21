<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Retour extends Model
{
    protected $table = 'retours';

    protected $fillable = ['ligne_commande_id', 'fournisseur_id', 'motif', 'statut_retour', 'date_retour'];

    protected function casts(): array
    {
        return ['date_retour' => 'datetime'];
    }

    public function ligneCommande(): BelongsTo
    {
        return $this->belongsTo(LigneCommande::class, 'ligne_commande_id');
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id', 'user_id');
    }
}
