<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Vitrine "Boutique" d'un utilisateur — voir la migration vitrines. Un seul
 * lien public par utilisateur (contrairement à LienAffilie, un par produit),
 * qui ouvre toute sa sélection de produits ; le QR de l'affiche A4 pointe
 * vers la même adresse avec `?src=qr` pour distinguer scans et clics.
 */
class Vitrine extends Model
{
    protected $table = 'vitrines';

    protected $fillable = ['user_id', 'code', 'clics', 'scans'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Récupère (ou crée) la vitrine de l'utilisateur — idempotent, comme LienAffilie::firstOrCreate(). */
    public static function pour(User $user): self
    {
        // Compteurs explicites : firstOrCreate() ne relit pas les défauts SQL, une vitrine
        // tout juste créée aurait sinon `clics`/`scans` à null.
        return self::firstOrCreate(['user_id' => $user->id], ['code' => self::genererCode(), 'clics' => 0, 'scans' => 0]);
    }

    public function url(): string
    {
        return rtrim((string) config('services.page_commande.url'), '/')."/boutique/vitrine/{$this->code}";
    }

    /** Adresse encodée dans le QR de l'affiche — la même que le lien, marquée pour compter les scans à part. */
    public function urlQr(): string
    {
        return $this->url().'?src=qr';
    }

    private static function genererCode(): string
    {
        do {
            $code = Str::lower(Str::random(8));
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
