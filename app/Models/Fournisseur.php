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
        'nom_gerant', 'telephone_gerant', 'horaires_ouverture', 'lien_maps', 'zone_couverte',
        'taux_commission', 'solde_portefeuille',
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

    /**
     * Fiche du fournisseur telle que la voit un livreur qui revend ses produits
     * ou va récupérer un colis : de quoi le joindre et le trouver. Jamais le
     * taux de commission ni le solde du portefeuille (données financières).
     * Le numéro à appeler est celui du gérant, à défaut le contact pro, à
     * défaut celui du compte.
     */
    public function fichePourLivreur(): array
    {
        $this->loadMissing('user');
        $telephone = $this->telephone_gerant ?: ($this->contact_pro ?: $this->user?->telephone);

        return [
            'user_id' => $this->user_id,
            'nom_entreprise' => $this->nom_entreprise,
            'nom_gerant' => $this->nom_gerant,
            'telephone' => $telephone,
            'whatsapp_url' => lien_whatsapp($telephone),
            'contact_pro' => $this->contact_pro && $this->contact_pro !== $telephone ? $this->contact_pro : null,
            'adresse' => $this->adresse_entreprise,
            'horaires' => $this->horaires_ouverture,
            'zone_couverte' => $this->zone_couverte,
            'lien_maps' => $this->lien_maps,
            'photo' => $this->user?->photo,
        ];
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
            'statut' => STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE,
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

    /**
     * "Payer tout" (écran Portefeuille fournisseur) — bascule tous les
     * crédits en_attente en payé et crée un débit unique du total, plutôt que
     * enregistrerPaiement() qui reste un débit manuel libre sans lien avec
     * des crédits précis. $referencePaiement (Wave, Orange Money, etc.) est
     * saisie par le coordinateur au moment du règlement réel hors app — copiée
     * telle quelle sur chaque crédit réglé par ce paiement.
     */
    public function payerCreditsEnAttente(int $acteurId, string $referencePaiement): TransactionPortefeuilleFournisseur
    {
        $creditsEnAttente = $this->transactionsPortefeuille()
            ->where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)
            ->where('statut', STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE);

        $total = (float) $creditsEnAttente->sum('montant');

        $creditsEnAttente->update([
            'statut' => STATUT_TRANSACTION_PORTEFEUILLE_PAYE,
            'reference_paiement' => $referencePaiement,
        ]);

        return $this->debiterPortefeuille($total, 'Paiement groupé des ventes en attente', $acteurId);
    }
}
