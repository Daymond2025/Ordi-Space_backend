<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coordinateur extends Model
{
    protected $table = 'coordinateurs';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id', 'adresse', 'horaires', 'zone_couverte'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function commandesValidees(): HasMany
    {
        return $this->hasMany(Commande::class, 'coordinateur_id', 'user_id');
    }

    public function validationsProduits(): HasMany
    {
        return $this->hasMany(ValidationProduit::class, 'coordinateur_id', 'user_id');
    }

    /**
     * Fiche du coordinateur "qui gère les missions" d'un livreur (carte "Ton
     * coordinateur" de "Mes infos" et du profil Boutique) : celui qui a validé
     * la plus récente des commandes que ce livreur livre ou a livrées. Null
     * tant qu'aucune mission ne lui a été confiée — un livreur n'est pas
     * rattaché à un coordinateur autrement.
     */
    public static function fichePourLivreur(User $livreur): ?array
    {
        $coordinateurId = Commande::whereHas('livraison', fn ($q) => $q->where('livreur_id', $livreur->id))
            ->whereNotNull('coordinateur_id')
            ->orderByDesc('date_validation')
            ->value('coordinateur_id');

        $coordinateur = $coordinateurId ? self::with('user')->find($coordinateurId) : null;
        if (! $coordinateur) {
            return null;
        }

        return [
            'nom' => trim(($coordinateur->user->prenom ?? '').' '.($coordinateur->user->nom ?? '')),
            'photo' => $coordinateur->user->photo,
            'telephone' => $coordinateur->user->telephone,
            'whatsapp_url' => lien_whatsapp($coordinateur->user->telephone),
            'adresse' => $coordinateur->adresse,
            'horaires' => $coordinateur->horaires,
            'zone_couverte' => $coordinateur->zone_couverte,
        ];
    }
}
