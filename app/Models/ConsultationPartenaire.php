<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dernière lecture d'un fil partenaire (produit ou commande) par un utilisateur. */
class ConsultationPartenaire extends Model
{
    protected $table = 'consultations_partenaire';

    public $timestamps = false;

    protected $fillable = ['user_id', 'cible', 'partenaire_id', 'cible_id', 'consulte_le'];

    protected function casts(): array
    {
        return ['consulte_le' => 'datetime'];
    }

    public static function marquer(int $userId, string $cible, int $partenaireId, int $cibleId): void
    {
        static::updateOrCreate(
            ['user_id' => $userId, 'cible' => $cible, 'partenaire_id' => $partenaireId, 'cible_id' => $cibleId],
            ['consulte_le' => now()],
        );
    }
}
