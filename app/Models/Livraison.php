<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Livraison extends Model
{
    protected $table = 'livraisons';

    protected $fillable = [
        'commande_id', 'livreur_id', 'adresse_id', 'date_prise_en_charge', 'colis_recupere_le',
        'livraison_demarree_le', 'arrivee_le', 'date_livraison_prevue', 'date_livraison_effective', 'statut_livraison',
        'preuve_livraison', 'retour_necessaire', 'statut_retour', 'date_retour_effectue',
    ];

    protected function casts(): array
    {
        return [
            'date_prise_en_charge' => 'datetime',
            'colis_recupere_le' => 'datetime',
            'livraison_demarree_le' => 'datetime',
            'arrivee_le' => 'datetime',
            'date_livraison_prevue' => 'datetime',
            'date_livraison_effective' => 'datetime',
            'date_retour_effectue' => 'datetime',
            'retour_necessaire' => 'boolean',
        ];
    }

    /**
     * Bascule vers "livrée" avec tous les effets de bord métier associés
     * (garantie, crédit parrainage/fournisseurs, statut commande, journal) —
     * partagé entre livrer() (photo de preuve) et la confirmation d'un
     * paiement Mobile Money (webhook Wave ou secours manuel), qui marque
     * elle aussi la mission comme terminée puisqu'aucun mockup de ce
     * parcours ne prévoit d'étape de preuve photo séparée. Idempotent : un
     * webhook Wave dupliqué ne doit pas régénérer garantie/crédits.
     */
    public function marquerLivree(): void
    {
        if ($this->statut_livraison === STATUT_LIVRAISON_LIVREE) {
            return;
        }

        $this->update([
            'statut_livraison' => STATUT_LIVRAISON_LIVREE,
            'date_livraison_effective' => now(),
        ]);

        $this->commande()->update(['statut_commande' => STATUT_COMMANDE_LIVREE]);

        Garantie::genererPourCommande($this->commande);
        $this->commande->crediterParrainageSiEligible();
        $this->commande->crediterFournisseursSiEligible();

        JournalAudit::enregistrer(
            $this->commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            "Commande n°{$this->commande_id} livrée.",
            commandeId: $this->commande_id,
        );

        if ($this->livreur_id !== null) {
            NotificationOrdispace::create([
                'user_id' => $this->livreur_id,
                'type_notification' => 'livraison_validee',
                'titre' => 'Livraison validée',
                'contenu' => "La livraison de la commande n°{$this->commande_id} a été validée.",
                'lu' => false,
                'date_envoi' => now(),
            ]);
        }
    }

    /**
     * En base, un chemin relatif sur le disque "public" — jamais une URL
     * absolue (même convention que PreuveReclamation::fichier()).
     */
    protected function preuveLivraison(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Storage::disk(IMAGE_PRODUIT_DISQUE)->url($value) : null,
        );
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class, 'livreur_id', 'user_id');
    }

    public function adresse(): BelongsTo
    {
        return $this->belongsTo(Adresse::class, 'adresse_id');
    }
}
