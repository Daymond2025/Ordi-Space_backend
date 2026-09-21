<?php

namespace App\Models;

use App\Services\CaracteristiquesProduit;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Produit extends Model
{
    protected $table = 'produits';

    /**
     * commission_ordispace est un accesseur calculé (pas de colonne) — sans
     * $appends il n'apparaîtrait jamais dans les réponses JSON consommées
     * par le frontend.
     */
    protected $appends = ['commission_ordispace'];

    protected $fillable = [
        'fournisseur_id', 'categorie_id', 'nom_produit', 'description',
        'prix', 'quantite_stock', 'statut_produit', 'date_ajout',
        'type_livraison', 'duree_garantie_mois', 'est_booste',
        'processeur', 'memoire_ram', 'stockage', 'taille',
        'systeme_exploitation', 'carte_graphique', 'couleur', 'cadeaux', 'etat_produit',
        'prix_vente', 'commission_agent', 'commission_apporteur', 'commission_revente',
        'pourcentage_reduction', 'prix_barre', 'marque',
    ];

    /**
     * Filtres du catalogue : `marque` est normalisée (majuscules pour les marques
     * du filtre, déduite du nom si vide) et `ram_go`/`stockage_go`/`taille_pouces`
     * sont recalculées à chaque enregistrement à partir des caractéristiques en
     * texte libre — jamais saisies à la main, elles ne peuvent donc pas diverger.
     */
    protected static function booted(): void
    {
        static::saving(function (Produit $produit) {
            $produit->marque = CaracteristiquesProduit::normaliserMarque($produit->marque)
                ?? CaracteristiquesProduit::marqueDepuisNom($produit->nom_produit);

            $produit->ram_go = CaracteristiquesProduit::capaciteEnGo($produit->memoire_ram);
            $produit->stockage_go = CaracteristiquesProduit::capaciteEnGo($produit->stockage);
            $produit->taille_pouces = CaracteristiquesProduit::taillePouces($produit->taille);
        });
    }

    protected function casts(): array
    {
        return [
            'prix' => 'decimal:2',
            'prix_vente' => 'decimal:2',
            'commission_agent' => 'decimal:2',
            'commission_apporteur' => 'decimal:2',
            'commission_revente' => 'decimal:2',
            'prix_barre' => 'decimal:2',
            'pourcentage_reduction' => 'integer',
            'date_ajout' => 'datetime',
            'est_booste' => 'boolean',
            'cadeaux' => 'array',
        ];
    }

    /**
     * Marge Ordi'Space = prix de vente − prix partenaire (`prix`) — calculée
     * à la volée (jamais stockée) pour rester cohérente même si l'un des
     * deux prix est modifié séparément après publication.
     */
    protected function commissionOrdispace(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->prix_vente !== null ? round($this->prix_vente - $this->prix, 2) : null,
        );
    }

    public function estNumerique(): bool
    {
        return $this->type_livraison === TYPE_LIVRAISON_NUMERIQUE;
    }

    /**
     * true pour les accessoires/logiciels publiés directement par
     * l'Administrateur, sans fournisseur ni cycle de validation coordinateur.
     */
    public function estPublieParAdmin(): bool
    {
        return $this->fournisseur_id === null;
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id', 'user_id');
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class, 'categorie_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ImageProduit::class, 'produit_id')->orderBy('ordre_affichage');
    }

    public function validations(): HasMany
    {
        return $this->hasMany(ValidationProduit::class, 'produit_id');
    }

    public function lignesCommande(): HasMany
    {
        return $this->hasMany(LigneCommande::class, 'produit_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'produit_id');
    }

    public function fraisLivraison(): HasMany
    {
        return $this->hasMany(FraisLivraisonProduit::class, 'produit_id');
    }

    /**
     * Discussion produit (Espace Coordinateur) — coordinateur/admin/commercial
     * toujours, fournisseur uniquement sur son propre produit.
     */
    public function estAccessibleConversationPar(User $user): bool
    {
        return match ($user->type_utilisateur) {
            ROLE_ADMINISTRATEUR, ROLE_COORDINATEUR, ROLE_COMMERCIAL => true,
            ROLE_FOURNISSEUR => $this->fournisseur_id === $user->id,
            default => false,
        };
    }

    public function estVisibleALaVente(): bool
    {
        return $this->statut_produit === STATUT_PRODUIT_VALIDE;
    }

    /**
     * "reçues = livrées + en_cours + annulées", sans trou ni double-compte —
     * en_cours regroupe tout ce qui n'est ni livré ni annulé (y compris les
     * 3 statuts "problème" de la Phase 1). Utilisé par la discussion produit
     * et le fil d'activité récente de l'accueil (MessageController).
     */
    public function statistiquesCommandes(): array
    {
        $base = Commande::whereHas('lignes', fn ($q) => $q->where('produit_id', $this->id));

        return [
            'recues' => (clone $base)->count(),
            'livrees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_LIVREE)->count(),
            'annulees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_ANNULEE)->count(),
            'en_cours' => (clone $base)->whereNotIn('statut_commande', [STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_ANNULEE])->count(),
        ];
    }
}
