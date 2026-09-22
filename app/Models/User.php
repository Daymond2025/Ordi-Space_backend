<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'telephone',
        'photo',
        'password',
        'type_utilisateur',
        'statut_compte',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_code',
        'two_factor_expires_at',
        'two_factor_tentatives',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'derniere_connexion' => 'datetime',
            'two_factor_expires_at' => 'datetime',
            'password_reset_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * En base, un chemin relatif sur le disque "public" — jamais une URL
     * absolue (même convention que Livraison::preuveLivraison()).
     */
    protected function photo(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Storage::disk(IMAGE_PRODUIT_DISQUE)->url($value) : null,
        );
    }

    public function requiresTwoFactor(): bool
    {
        return in_array($this->type_utilisateur, roles_2fa_obligatoire(), true);
    }

    /**
     * Génère un nouveau code OTP, le stocke (haché) avec son expiration, et
     * le retourne en clair pour que l'appelant choisisse le canal d'envoi
     * (e-mail pour le personnel, WhatsApp pour le Client).
     */
    public function emettreCodeOtp(): string
    {
        $code = generate_otp_code();

        $this->forceFill([
            'two_factor_code' => Hash::make($code),
            'two_factor_expires_at' => now()->addMinutes(OTP_EXPIRATION_MINUTES),
            'two_factor_tentatives' => 0,
        ])->save();

        return $code;
    }

    /** Personnel identifié par e-mail/mot de passe — seuls ces rôles peuvent demander une réinitialisation. Client se connecte par téléphone/OTP, sans mot de passe utile. */
    public function peutReinitialiserMotDePasse(): bool
    {
        return $this->type_utilisateur !== ROLE_CLIENT;
    }

    /**
     * Génère un nouveau code de réinitialisation, le stocke (haché) avec son
     * expiration, et le retourne en clair pour l'envoi par e-mail — même
     * mécanique que emettreCodeOtp(), colonnes dédiées (voir migration
     * add_password_reset_to_users_table) pour ne jamais interférer avec une
     * connexion à deux facteurs en cours.
     */
    public function emettreCodeReinitialisation(): string
    {
        $code = generate_otp_code();

        $this->forceFill([
            'password_reset_code' => Hash::make($code),
            'password_reset_expires_at' => now()->addMinutes(PASSWORD_RESET_EXPIRATION_MINUTES),
            'password_reset_tentatives' => 0,
        ])->save();

        return $code;
    }

    public function fournisseur(): HasOne
    {
        return $this->hasOne(Fournisseur::class);
    }

    public function commercial(): HasOne
    {
        return $this->hasOne(Commercial::class);
    }

    public function coordinateur(): HasOne
    {
        return $this->hasOne(Coordinateur::class);
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    public function livreur(): HasOne
    {
        return $this->hasOne(Livreur::class);
    }

    public function technicien(): HasOne
    {
        return $this->hasOne(TechnicienMaintenance::class);
    }

    public function administrateur(): HasOne
    {
        return $this->hasOne(Administrateur::class);
    }

    /**
     * Relation de profil correspondant à type_utilisateur (spécialisation MCD).
     */
    public function profil(): HasOne
    {
        return match ($this->type_utilisateur) {
            ROLE_FOURNISSEUR => $this->fournisseur(),
            ROLE_COMMERCIAL => $this->commercial(),
            ROLE_COORDINATEUR => $this->coordinateur(),
            ROLE_CLIENT => $this->client(),
            ROLE_LIVREUR => $this->livreur(),
            ROLE_TECHNICIEN_MAINTENANCE => $this->technicien(),
            ROLE_ADMINISTRATEUR => $this->administrateur(),
        };
    }
}
