<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vente d'un produit déclarée par le fournisseur HORS du flux commande
 * in-app — voir la note sur STATUT_ACHAT_EXTERNE_* dans app/Helpers/const.php.
 */
class AchatExterne extends Model
{
    protected $table = 'achats_externes';

    protected $fillable = [
        'produit_id', 'fournisseur_id', 'montant_vente', 'taux_commission_applique',
        'commission_due', 'statut', 'reference_paiement', 'date_vente', 'note',
        'montant_modifie_propose', 'motif_modification', 'statut_modification',
    ];

    protected function casts(): array
    {
        return [
            'montant_vente' => 'decimal:2',
            'taux_commission_applique' => 'decimal:2',
            'commission_due' => 'decimal:2',
            'montant_modifie_propose' => 'decimal:2',
            'date_vente' => 'date',
        ];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id', 'user_id');
    }
}
