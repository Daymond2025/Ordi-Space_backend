<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglage global clé → valeur (voir la migration parametres) : lu par les
 * apps, écrit par l'Administrateur.
 */
class Parametre extends Model
{
    protected $table = 'parametres';

    protected $fillable = ['cle', 'valeur'];

    public static function lire(string $cle): ?string
    {
        return self::where('cle', $cle)->value('valeur');
    }

    public static function ecrire(string $cle, ?string $valeur): void
    {
        self::updateOrCreate(['cle' => $cle], ['valeur' => $valeur]);
    }
}
