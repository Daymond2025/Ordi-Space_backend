<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fournisseur extends Model
{
    protected $table = 'fournisseurs';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = [
        'user_id', 'nom_entreprise', 'adresse_entreprise', 'contact_pro',
        'nom_gerant', 'horaires_ouverture', 'lien_maps', 'taux_commission', 'solde_portefeuille',
    ];

    protected function casts(): array
    {
        return [
            'taux_commission' => 'decimal:2',
            'solde_portefeuille' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class, 'fournisseur_id', 'user_id');
    }

    public function retours(): HasMany
    {
        return $this->hasMany(Retour::class, 'fournisseur_id', 'user_id');
    }

    public function transactionsPortefeuille(): HasMany
    {
        return $this->hasMany(TransactionPortefeuilleFournisseur::class, 'fournisseur_id', 'user_id');
    }

    /**
     * Crédit automatique (vente livrée) — solde positif = ce qu'OrdiSpace
     * doit au fournisseur. Calqué sur Client::crediterPortefeuille().
     * $commissionPrelevee : part gardée par OrdiSpace sur cette vente (montant
     * brut - montant net crédité) — utilisée pour "Commission totale, reçu"
     * sur l'accueil Coordinateur, jamais recalculée après coup.
     */
    public function crediterPortefeuille(float $montant, string $motif, ?int $commandeId = null, ?float $commissionPrelevee = null): TransactionPortefeuilleFournisseur
    {
        $this->increment('solde_portefeuille', $montant);

        return TransactionPortefeuilleFournisseur::create([
            'fournisseur_id' => $this->user_id,
            'type' => TYPE_TRANSACTION_PORTEFEUILLE_CREDIT,
            'montant' => $montant,
            'commission_prelevee' => $commissionPrelevee,
            'motif' => $motif,
            'commande_id' => $commandeId,
            'acteur_id' => null,
            'solde_apres' => $this->solde_portefeuille,
            'date_transaction' => now(),
        ]);
    }

    /**
     * Paiement enregistré manuellement (Admin ou Coordinateur) — peut faire
     * passer le solde en négatif si le montant payé dépasse ce qui était dû,
     * ce qui signifie alors que le fournisseur doit la différence à OrdiSpace.
     */
    public function debiterPortefeuille(float $montant, string $motif, ?int $acteurId, ?int $commandeId = null): TransactionPortefeuilleFournisseur
    {
        $this->decrement('solde_portefeuille', $montant);

        return TransactionPortefeuilleFournisseur::create([
            'fournisseur_id' => $this->user_id,
            'type' => TYPE_TRANSACTION_PORTEFEUILLE_DEBIT,
            'montant' => $montant,
            'motif' => $motif,
            'commande_id' => $commandeId,
            'acteur_id' => $acteurId,
            'solde_apres' => $this->solde_portefeuille,
            'date_transaction' => now(),
        ]);
    }
}
