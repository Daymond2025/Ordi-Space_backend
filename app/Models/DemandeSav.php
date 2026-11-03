<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandeSav extends Model
{
    protected $table = 'demandes_sav';

    protected $fillable = ['client_id', 'garantie_id', 'description_probleme', 'statut_demande', 'date_demande'];

    protected function casts(): array
    {
        return ['date_demande' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function garantie(): BelongsTo
    {
        return $this->belongsTo(Garantie::class, 'garantie_id');
    }

    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class, 'demande_sav_id');
    }

    /**
     * Périmètre d'un technicien : demandes sans rendez-vous encore
     * (nouvelles, non affectées) et celles dont un rendez-vous lui est
     * affecté ou n'est affecté à personne.
     */
    public function scopeVisiblesPourTechnicien(Builder $query, int $technicienId): void
    {
        $query->where(fn ($q) => $q->whereDoesntHave('rendezVous')
            ->orWhereHas('rendezVous', fn ($rv) => $rv->where('technicien_id', $technicienId)->orWhereNull('technicien_id')));
    }

    /**
     * Lecture/traitement d'une demande : le client propriétaire, le
     * technicien dans son périmètre, sinon le service maintenance
     * (sav.traiter : coordinateur, admin). Jamais un livreur/fournisseur.
     */
    public function estAccessiblePar(User $user): bool
    {
        return match ($user->type_utilisateur) {
            ROLE_CLIENT => $this->client_id === $user->id,
            ROLE_TECHNICIEN_MAINTENANCE => static::whereKey($this->id)->visiblesPourTechnicien($user->id)->exists(),
            default => $user->can(PERMISSION_SAV_TRAITER),
        };
    }
}
