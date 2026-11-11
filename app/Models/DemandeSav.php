<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandeSav extends Model
{
    protected $table = 'demandes_sav';

    protected $fillable = [
        'client_id', 'garantie_id', 'description_probleme', 'statut_demande', 'date_demande',
        'partenaire_id', 'ligne_commande_partenaire_id', 'transferee_le', 'motif_transfert',
    ];

    protected function casts(): array
    {
        return ['date_demande' => 'datetime', 'transferee_le' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function garantie(): BelongsTo
    {
        return $this->belongsTo(Garantie::class, 'garantie_id');
    }

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'partenaire_id', 'user_id');
    }

    public function ligneCommandePartenaire(): BelongsTo
    {
        return $this->belongsTo(LigneCommandePartenaire::class, 'ligne_commande_partenaire_id');
    }

    /**
     * Circuit SAV Ordi'Space (techniciens, coordinateur, admin) : une panne
     * sur un produit vendu par un partenaire n'y entre qu'une fois que le
     * partenaire l'a transférée.
     */
    public function scopeCoteOrdispace(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('partenaire_id')->orWhereNotNull('transferee_le'));
    }

    public function estCoteOrdispace(): bool
    {
        return $this->partenaire_id === null || $this->transferee_le !== null;
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
            ROLE_TECHNICIEN_MAINTENANCE => $this->estCoteOrdispace() && static::whereKey($this->id)->visiblesPourTechnicien($user->id)->exists(),
            ROLE_PARTENAIRE => false, // voir Api\Partenaire\SavController (ses propres demandes uniquement)
            default => $this->estCoteOrdispace() && $user->can(PERMISSION_SAV_TRAITER),
        };
    }
}
