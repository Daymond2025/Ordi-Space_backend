<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Client extends Model
{
    protected $table = 'clients';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['user_id', 'date_inscription', 'code_parrainage', 'solde_portefeuille'];

    protected function casts(): array
    {
        return ['date_inscription' => 'date', 'solde_portefeuille' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function adresses(): HasMany
    {
        return $this->hasMany(Adresse::class, 'client_id', 'user_id');
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(Commande::class, 'client_id', 'user_id');
    }

    public function demandesSav(): HasMany
    {
        return $this->hasMany(DemandeSav::class, 'client_id', 'user_id');
    }

    public function reclamations(): HasMany
    {
        return $this->hasMany(Reclamation::class, 'client_id', 'user_id');
    }

    public function panier(): HasOne
    {
        return $this->hasOne(Panier::class, 'client_id', 'user_id');
    }

    public function progressionsTutoriels(): HasMany
    {
        return $this->hasMany(ProgressionTutoriel::class, 'client_id', 'user_id');
    }

    public function filleuls(): HasMany
    {
        return $this->hasMany(Commande::class, 'parrain_id', 'user_id');
    }

    public function transactionsPortefeuille(): HasMany
    {
        return $this->hasMany(TransactionPortefeuille::class, 'client_id', 'user_id');
    }

    /**
     * Format imposé : ACHAT-{2 premières lettres du nom}-2026. Un suffixe
     * numérique est ajouté en cas de collision (deux noms partageant les
     * mêmes initiales) — le code reste stable une fois attribué.
     */
    public static function genererCodeParrainage(string $nom): string
    {
        $initiales = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $nom) ?: 'XX', 0, 2));
        $base = PARRAINAGE_CODE_PREFIXE."-{$initiales}-".PARRAINAGE_CODE_SUFFIXE;
        $code = $base;
        $suffixe = 1;

        while (static::where('code_parrainage', $code)->exists()) {
            $code = "{$base}-{$suffixe}";
            $suffixe++;
        }

        return $code;
    }

    /**
     * Compte Client minimal (nom + téléphone, sans e-mail ni mot de passe
     * utilisable) — utilisé par la connexion/inscription par téléphone
     * (TelephoneAuthController::inscrire) et par l'enregistrement d'une
     * vente pour un client sans compte préalable (ClientRapideController).
     * Le téléphone doit déjà être normalisé (E.164) et son unicité vérifiée
     * par l'appelant.
     */
    public static function creerCompteMinimal(string $nom, ?string $prenom, string $telephoneE164): User
    {
        return DB::transaction(function () use ($nom, $prenom, $telephoneE164) {
            $user = User::create([
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => null,
                'telephone' => $telephoneE164,
                'password' => Hash::make(Str::random(40)),
                'type_utilisateur' => ROLE_CLIENT,
                'statut_compte' => STATUT_COMPTE_ACTIF,
            ]);

            static::create([
                'user_id' => $user->id,
                'code_parrainage' => static::genererCodeParrainage($nom),
            ]);

            $user->assignRole(ROLE_CLIENT);

            return $user;
        });
    }

    /**
     * Segmentation admin sur le nombre de commandes valides (hors annulées).
     * Règle PDG : Nouveau Client / Gros Acheteur / VIP — voir Helpers/const.php.
     */
    public static function segmentDepuisCommandes(int $nombreCommandes): ?string
    {
        return match (true) {
            $nombreCommandes >= SEUIL_VIP_MIN => SEGMENT_CLIENT_VIP,
            $nombreCommandes >= SEUIL_GROS_ACHETEUR_MIN => SEGMENT_CLIENT_GROS_ACHETEUR,
            $nombreCommandes >= SEUIL_NOUVEAU_CLIENT_MIN => SEGMENT_CLIENT_NOUVEAU,
            default => null,
        };
    }

    public function crediterPortefeuille(float $montant, string $motif, ?int $commandeId = null): TransactionPortefeuille
    {
        $this->increment('solde_portefeuille', $montant);

        return TransactionPortefeuille::create([
            'client_id' => $this->user_id,
            'type' => TYPE_TRANSACTION_PORTEFEUILLE_CREDIT,
            'montant' => $montant,
            'motif' => $motif,
            'commande_id' => $commandeId,
            'solde_apres' => $this->solde_portefeuille,
            'date_transaction' => now(),
        ]);
    }
}
