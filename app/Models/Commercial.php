<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commercial extends Model
{
    protected $table = 'commerciaux';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = [
        'user_id', 'type_commercial', 'matricule', 'nom_modele_ia', 'nom_entreprise', 'localisation', 'solde_portefeuille',
    ];

    protected function casts(): array
    {
        return ['solde_portefeuille' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(Commande::class, 'commercial_id', 'user_id');
    }

    public function transactionsPortefeuille(): HasMany
    {
        return $this->hasMany(TransactionPortefeuilleCommercial::class, 'commercial_id', 'user_id');
    }

    public function estAgentIa(): bool
    {
        return $this->type_commercial === TYPE_COMMERCIAL_IA;
    }

    /** "Commande validé" (+commission_agent cumulé des lignes) — voir Commande::crediterCommissionCommercialSiEligible(). */
    public function crediterPortefeuille(float $montant, string $libelle, ?int $commandeId, ?string $clientNom, ?string $clientLocalite): TransactionPortefeuilleCommercial
    {
        $this->increment('solde_portefeuille', $montant);

        return TransactionPortefeuilleCommercial::create([
            'commercial_id' => $this->user_id,
            'commande_id' => $commandeId,
            'type' => TYPE_TRANSACTION_PORTEFEUILLE_CREDIT,
            'montant' => $montant,
            'libelle' => $libelle,
            'client_nom' => $clientNom,
            'client_localite' => $clientLocalite,
            'solde_apres' => $this->solde_portefeuille,
            'date_transaction' => now(),
        ]);
    }

    /** "Commande annulée" (commande validée puis annulée : reprise de la commission déjà créditée) ou "Retrait effectué" (vrai retrait Mobile Money validé par l'Admin, voir Admin\RetraitController). */
    public function debiterPortefeuille(float $montant, string $libelle, ?int $commandeId, ?string $clientNom, ?string $clientLocalite): TransactionPortefeuilleCommercial
    {
        $this->decrement('solde_portefeuille', $montant);

        return TransactionPortefeuilleCommercial::create([
            'commercial_id' => $this->user_id,
            'commande_id' => $commandeId,
            'type' => TYPE_TRANSACTION_PORTEFEUILLE_DEBIT,
            'montant' => $montant,
            'libelle' => $libelle,
            'client_nom' => $clientNom,
            'client_localite' => $clientLocalite,
            'solde_apres' => $this->solde_portefeuille,
            'date_transaction' => now(),
        ]);
    }
}
