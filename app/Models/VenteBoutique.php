<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vente "Boutique" d'un Livreur — voir la migration ventes_boutique. C'est
 * une commande ordinaire (client, adresse, livraison, validation par le
 * Coordinateur/Admin) à laquelle on rattache le livreur vendeur et la
 * commission qu'il touchera ; elle ne duplique aucune donnée de la commande.
 */
class VenteBoutique extends Model
{
    protected $table = 'ventes_boutique';

    protected $fillable = ['livreur_id', 'commande_id', 'lien_affilie_id', 'source', 'commission'];

    protected function casts(): array
    {
        return ['commission' => 'decimal:2'];
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'livreur_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function lienAffilie(): BelongsTo
    {
        return $this->belongsTo(LienAffilie::class);
    }

    /**
     * Enregistre la vente d'une commande déjà créée et fige la commission :
     * somme des `commission_revente` des produits vendus × quantités (un
     * produit sans commission n'en rapporte pas).
     */
    public static function enregistrer(User $livreur, Commande $commande, string $source, ?LienAffilie $lien = null): self
    {
        $commande->loadMissing('lignes.produit');

        $commission = $commande->lignes->sum(
            fn (LigneCommande $ligne) => (float) ($ligne->produit?->commission_revente ?? 0) * $ligne->quantite
        );

        $vente = self::create([
            'livreur_id' => $livreur->id,
            'commande_id' => $commande->id,
            'lien_affilie_id' => $lien?->id,
            'source' => $source,
            'commission' => $commission,
        ]);

        $lien?->update(['derniere_activite_le' => now()]);

        return $vente;
    }

    /**
     * Regroupement des statuts de commande sous les 4 états de l'écran
     * "Centre des ventes" : tout ce qui est validé mais pas encore livré
     * (préparation, livraison, report, client injoignable…) est "en cours".
     *
     * @return array<string, list<string>>
     */
    public static function statutsCommandeParStatutAffiche(): array
    {
        return [
            'en_attente' => [STATUT_COMMANDE_EN_ATTENTE],
            'en_cours' => [
                STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_EN_PREPARATION, STATUT_COMMANDE_EN_LIVRAISON,
                STATUT_COMMANDE_REPORTEE, STATUT_COMMANDE_CLIENT_INJOIGNABLE, STATUT_COMMANDE_NUMERO_INCORRECT,
            ],
            'livree' => [STATUT_COMMANDE_LIVREE],
            'annulee' => [STATUT_COMMANDE_ANNULEE],
        ];
    }

    /**
     * Statuts de commande dont la commission est acquise au livreur : la
     * commande a été validée par l'Admin/Coordinateur (déclencheur décidé
     * pour la Boutique) et n'a pas été annulée depuis — donc tout "en cours"
     * et "livrée", pas "en attente" ni "annulée".
     *
     * @return list<string>
     */
    public static function statutsCommandeAcquerantCommission(): array
    {
        $parStatut = self::statutsCommandeParStatutAffiche();

        return [...$parStatut['en_cours'], ...$parStatut['livree']];
    }

    public function statutAffiche(): string
    {
        foreach (self::statutsCommandeParStatutAffiche() as $statut => $statutsCommande) {
            if (in_array($this->commande->statut_commande, $statutsCommande, true)) {
                return $statut;
            }
        }

        return 'en_cours';
    }
}
