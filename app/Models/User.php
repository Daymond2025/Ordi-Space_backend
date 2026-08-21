<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        'password',
        'type_utilisateur',
        'statut_compte',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_code',
        'two_factor_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'derniere_connexion' => 'datetime',
            'two_factor_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function requiresTwoFactor(): bool
    {
        return in_array($this->type_utilisateur, roles_2fa_obligatoire(), true);
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
