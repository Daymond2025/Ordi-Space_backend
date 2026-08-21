<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbonnementGarantix extends Model
{
    protected $table = 'abonnements_garantix';

    protected $fillable = [
        'client_id', 'ligne_commande_id', 'formule_garantix_id',
        'date_debut', 'date_fin', 'statut', 'interventions_utilisees',
        'mode_paiement', 'reference_transaction', 'statut_paiement', 'date_paiement',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'date_paiement' => 'datetime',
            'reference_transaction' => 'encrypted',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function ligneCommande(): BelongsTo
    {
        return $this->belongsTo(LigneCommande::class, 'ligne_commande_id');
    }

    public function formule(): BelongsTo
    {
        return $this->belongsTo(FormuleGarantix::class, 'formule_garantix_id');
    }

    public function estActif(): bool
    {
        return $this->statut === STATUT_ABONNEMENT_GARANTIX_ACTIF && now()->lte($this->date_fin);
    }
}
