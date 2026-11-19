<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Adresse extends Model
{
    protected $table = 'adresses';

    protected $fillable = ['client_id', 'libelle', 'rue', 'precision_lieu', 'ville', 'pays', 'localite_id', 'lien_maps'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function localite(): BelongsTo
    {
        return $this->belongsTo(Localite::class, 'localite_id');
    }

    public function livraisons(): HasMany
    {
        return $this->hasMany(Livraison::class, 'adresse_id');
    }

    /**
     * "Ville, Localité" (ou juste la ville, texte libre historique), suivi
     * de la précision saisie par le commercial (quartier, repère…) — voir
     * ClientRapideController::creerAdresse().
     */
    public function libelleZone(): string
    {
        $zone = $this->localite ? "{$this->ville}, {$this->localite->nom}" : (string) $this->ville;

        return $this->precision_lieu ? "{$zone} — {$this->precision_lieu}" : $zone;
    }
}
