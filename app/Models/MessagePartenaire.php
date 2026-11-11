<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Message d'une conversation partenaire — même forme que Message (fil
 * produit / fil commande), mais table séparée : visible du partenaire et du
 * coordinateur/admin uniquement, jamais du fournisseur du produit.
 * `commande_id` est exposé (alias de commande_partenaire_id) pour garder la
 * forme MessageConversation des écrans existants.
 */
class MessagePartenaire extends Model
{
    protected $table = 'messages_partenaire';

    public $timestamps = false;

    protected $fillable = [
        'partenaire_id', 'produit_id', 'commande_partenaire_id', 'auteur_id', 'type', 'contenu', 'fichier', 'donnees', 'date_envoi',
    ];

    protected $appends = ['commande_id'];

    protected function casts(): array
    {
        return ['date_envoi' => 'datetime', 'donnees' => 'array'];
    }

    protected function fichier(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Storage::disk(IMAGE_PRODUIT_DISQUE)->url($value) : null,
        );
    }

    public function getCommandeIdAttribute(): ?int
    {
        return $this->commande_partenaire_id;
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
