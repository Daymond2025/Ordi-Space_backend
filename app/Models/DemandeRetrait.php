<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de retrait de commissions — voir la migration demandes_retrait et
 * le service PortefeuilleCommissions (déduction du solde disponible).
 */
class DemandeRetrait extends Model
{
    protected $table = 'demandes_retrait';

    protected $fillable = ['user_id', 'montant', 'operateur', 'telephone', 'statut', 'reference', 'remarque', 'admin_id', 'traite_le'];

    protected function casts(): array
    {
        return ['montant' => 'decimal:2', 'traite_le' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function estEnAttente(): bool
    {
        return $this->statut === STATUT_RETRAIT_EN_ATTENTE;
    }
}
