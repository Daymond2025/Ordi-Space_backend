<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Achat de stock par un partenaire auprès d'Ordi'Space, payé d'avance :
 * en_attente_paiement → paiement_declare (preuve) → paiement_valide (Admin)
 * → expedie (Admin) → recu (partenaire : le stock entre à ce moment).
 * Un paiement refusé repasse en en_attente_paiement avec un motif.
 */
class ApprovisionnementPartenaire extends Model
{
    protected $table = 'approvisionnements_partenaire';

    protected $fillable = [
        'reference', 'partenaire_id', 'commande_partenaire_id', 'statut', 'montant_total', 'mode_paiement', 'reference_paiement',
        'preuve_paiement', 'motif_refus', 'paiement_declare_le', 'paiement_valide_le', 'valide_par',
        'expedie_le', 'recu_le', 'annule_le',
    ];

    // Chemin interne du fichier : servi par une route authentifiée, jamais exposé.
    protected $hidden = ['preuve_paiement'];

    protected $appends = ['a_preuve_paiement'];

    protected function casts(): array
    {
        return [
            'montant_total' => 'decimal:2',
            'paiement_declare_le' => 'datetime',
            'paiement_valide_le' => 'datetime',
            'expedie_le' => 'datetime',
            'recu_le' => 'datetime',
            'annule_le' => 'datetime',
        ];
    }

    public static function genererReference(): string
    {
        do {
            $reference = 'APR-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function getAPreuvePaiementAttribute(): bool
    {
        return $this->preuve_paiement !== null;
    }

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'partenaire_id', 'user_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneApprovisionnementPartenaire::class, 'approvisionnement_id');
    }
}
