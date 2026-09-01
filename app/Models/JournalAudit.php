<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalAudit extends Model
{
    protected $table = 'journal_audit';
    public $timestamps = false;

    protected $fillable = ['user_id', 'commande_id', 'acteur_id', 'action', 'entite_concernee', 'details', 'donnees', 'adresse_ip', 'date_heure'];

    protected function casts(): array
    {
        return ['date_heure' => 'datetime', 'donnees' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    /**
     * L'acteur ayant effectué l'action — distinct de user_id (voir plus bas).
     */
    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }

    /**
     * Point d'entrée unique pour journaliser une action métier — alimente
     * le fil "Activités" de la fiche client admin. "$userId" désigne le
     * client concerné par l'action (c'est ce que lisent tous les appelants
     * existants), pas forcément celui qui l'a effectuée — d'où "acteurId",
     * distinct, déduit de l'utilisateur authentifié si non fourni.
     * "commandeId" alimente l'onglet "Suivi" (timeline) de l'Espace
     * Coordinateur — nullable, les appels existants restent inchangés.
     * "donnees" (ex: {statut_apres, livreur_id}) permet à cette même
     * timeline de choisir icône/couleur sans parser le texte de "details".
     */
    public static function enregistrer(
        int $userId,
        string $action,
        ?string $entiteConcernee = null,
        ?string $details = null,
        ?int $commandeId = null,
        ?int $acteurId = null,
        ?array $donnees = null,
    ): self {
        return static::create([
            'user_id' => $userId,
            'commande_id' => $commandeId,
            'acteur_id' => $acteurId ?? auth('sanctum')->id(),
            'action' => $action,
            'entite_concernee' => $entiteConcernee,
            'details' => $details,
            'donnees' => $donnees,
            'adresse_ip' => request()->ip(),
            'date_heure' => now(),
        ]);
    }
}
