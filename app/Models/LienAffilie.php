<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Lien affilié "Boutique" — voir BoutiqueController::genererLien(). Un
 * livreur qui clique "Vendre ce produit" obtient (ou récupère) le lien
 * public à partager pour ce produit précis.
 */
class LienAffilie extends Model
{
    protected $table = 'liens_affilies';

    protected $fillable = ['produit_id', 'livreur_id', 'code', 'vues', 'derniere_activite_le'];

    protected function casts(): array
    {
        return ['derniere_activite_le' => 'datetime'];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'livreur_id');
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(VenteBoutique::class);
    }

    /** Adresse publique à partager (page d'arrivée de l'acheteur dans l'appli Livreur). */
    public function url(): string
    {
        return rtrim((string) config('services.page_commande.url'), '/')."/boutique/produit/{$this->code}";
    }

    /**
     * Code court, non devinable (pas un id auto-incrémenté) — évite qu'un
     * concurrent énumère les liens d'un livreur en incrémentant un nombre.
     */
    public static function genererCode(): string
    {
        do {
            $code = Str::lower(Str::random(8));
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
