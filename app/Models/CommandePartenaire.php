<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Commande d'un client chez un partenaire, payée au partenaire. Mêmes
 * statuts que le circuit Ordi'Space (STATUT_COMMANDE_*), pilotés par le
 * partenaire depuis la feuille de statut (Partenaire\CommandeController::
 * changerStatut()) : le stock sort à la validation (STATUTS_CMD_PARTENAIRE_
 * STOCK_SORTI) et revient si la commande est annulée.
 */
class CommandePartenaire extends Model
{
    protected $table = 'commandes_partenaire';

    public const STATUTS_FINAUX = [STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_ANNULEE];

    /** Feuille de statut du partenaire — même vocabulaire que la feuille Coordinateur. */
    public const STATUTS_POSSIBLES = [
        STATUT_COMMANDE_EN_ATTENTE, STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_EN_PREPARATION, STATUT_COMMANDE_EN_LIVRAISON,
        STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_ANNULEE, STATUT_COMMANDE_REPORTEE, STATUT_COMMANDE_CLIENT_INJOIGNABLE,
        STATUT_COMMANDE_NUMERO_INCORRECT,
    ];

    /** Lecture du suivi et des conversations : le partenaire propriétaire, ou le coordinateur/admin. Jamais un fournisseur. */
    public function estAccessiblePar(User $user): bool
    {
        return $user->id === $this->partenaire_id
            || in_array($user->type_utilisateur, [ROLE_COORDINATEUR, ROLE_ADMINISTRATEUR], true);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MessagePartenaire::class, 'commande_partenaire_id');
    }

    public function suivis(): HasMany
    {
        return $this->hasMany(SuiviCommandePartenaire::class, 'commande_partenaire_id');
    }

    protected $fillable = [
        'reference', 'partenaire_id', 'client_id', 'localite_id', 'mode_remise', 'adresse_livraison',
        'telephone_contact', 'note', 'statut', 'montant_total', 'stock_sorti', 'motif_annulation',
        'acceptee_le', 'expediee_le', 'livree_le', 'annulee_le',
    ];

    protected function casts(): array
    {
        return [
            'montant_total' => 'decimal:2',
            'stock_sorti' => 'boolean',
            'acceptee_le' => 'datetime',
            'expediee_le' => 'datetime',
            'livree_le' => 'datetime',
            'annulee_le' => 'datetime',
        ];
    }

    public static function genererReference(): string
    {
        do {
            $reference = 'CPA-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function partenaire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'partenaire_id', 'user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function localite(): BelongsTo
    {
        return $this->belongsTo(Localite::class, 'localite_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommandePartenaire::class, 'commande_partenaire_id');
    }

    public function estFinale(): bool
    {
        return in_array($this->statut, self::STATUTS_FINAUX, true);
    }

    /** Bénéfice du partenaire : (prix payé − prix de cession) × quantité. */
    public function benefice(): float
    {
        return (float) $this->lignes->sum(fn (LigneCommandePartenaire $l) => ((float) $l->prix_unitaire - (float) $l->prix_cession_unitaire) * $l->quantite);
    }
}
