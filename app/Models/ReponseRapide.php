<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bibliothèque de réponses rapides (écran "Réponse rapide", app Commercial)
 * — voir migration create_reponses_rapides_table.
 */
class ReponseRapide extends Model
{
    protected $table = 'reponses_rapides';

    protected $fillable = ['titre', 'contenu', 'est_favori', 'nombre_copies'];

    protected $casts = [
        'est_favori' => 'boolean',
        'nombre_copies' => 'integer',
    ];
}
