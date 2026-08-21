<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UtilisationPrivilege extends Model
{
    protected $table = 'utilisations_privilege';
    public $timestamps = false;

    protected $fillable = ['privilege_id', 'client_id', 'commande_id', 'montant_remise', 'date_utilisation'];

    protected function casts(): array
    {
        return ['montant_remise' => 'decimal:2', 'date_utilisation' => 'datetime'];
    }

    public function privilege(): BelongsTo
    {
        return $this->belongsTo(Privilege::class, 'privilege_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }
}
