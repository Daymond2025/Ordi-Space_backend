<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Événement de la timeline "Suivie" d'une commande partenaire (forme SuiviEntree côté front). */
class SuiviCommandePartenaire extends Model
{
    protected $table = 'suivis_commande_partenaire';

    public $timestamps = false;

    protected $fillable = ['commande_partenaire_id', 'acteur_id', 'action', 'details', 'statut_avant', 'statut_apres', 'date_heure'];

    protected function casts(): array
    {
        return ['date_heure' => 'datetime'];
    }

    public static function enregistrer(CommandePartenaire $commande, string $action, string $details, ?int $acteurId, ?string $avant = null, ?string $apres = null): self
    {
        return static::create([
            'commande_partenaire_id' => $commande->id,
            'acteur_id' => $acteurId,
            'action' => $action,
            'details' => mb_substr($details, 0, 500),
            'statut_avant' => $avant,
            'statut_apres' => $apres,
            'date_heure' => now(),
        ]);
    }

    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }

    /** Forme SuiviEntree (TimelineSuivi.tsx). */
    public function presenter(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'details' => $this->details,
            'date_heure' => $this->date_heure,
            'acteur' => $this->acteur?->only(['id', 'nom', 'prenom', 'type_utilisateur']),
            'donnees' => ['statut_avant' => $this->statut_avant, 'statut_apres' => $this->statut_apres],
        ];
    }
}
