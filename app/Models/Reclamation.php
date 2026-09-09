<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reclamation extends Model
{
    protected $table = 'reclamations';

    protected $fillable = [
        'user_id', 'client_id', 'commande_id', 'sujet', 'titre', 'description', 'statut',
        'reponse_admin', 'date_reclamation', 'date_traitement',
    ];

    protected function casts(): array
    {
        return [
            'date_reclamation' => 'datetime',
            'date_traitement' => 'datetime',
        ];
    }

    /**
     * Auteur réel — n'importe laquelle des 5 entités qui peuvent déposer une
     * réclamation (Client, Fournisseur, Commercial, Livreur, Technicien
     * maintenance), toutes des tables clé user_id → users.id. Toujours
     * renseignée, contrairement à client() ci-dessous.
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Compat Ordi'Space_Admin_Web (écran /clients/reclamations, consomme déjà
     * client.user.*) — renseignée seulement quand l'auteur est un Client,
     * null pour les 4 autres entités. Préférer auteur() pour du nouveau code.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'user_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function preuves(): HasMany
    {
        return $this->hasMany(PreuveReclamation::class);
    }
}
