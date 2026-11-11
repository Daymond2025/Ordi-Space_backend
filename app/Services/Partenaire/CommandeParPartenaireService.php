<?php

namespace App\Services\Partenaire;

use App\Models\ApprovisionnementPartenaire;
use App\Models\Client;
use App\Models\CommandePartenaire;
use App\Models\OffrePartenaire;
use App\Models\Partenaire;
use App\Models\Produit;
use App\Models\StockPartenaire;
use App\Models\SuiviCommandePartenaire;
use App\Models\User;
use App\Services\NotificationAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Commande passée par le partenaire depuis son catalogue pour un client
 * (nom, numéro, lieu de livraison). Selon son stock :
 *  - en stock : commande validée tout de suite, stock sorti, rien à payer ;
 *  - sinon : commande en attente + approvisionnement des articles manquants
 *    (prix de cession). L'écran "Confirmation" (Wave) n'est qu'une
 *    formalité : le partenaire paie Ordi'Space hors application, confirme,
 *    puis l'Admin valide le paiement, expédie, et la réception valide la
 *    commande du client.
 * Le client reçoit un compte Ordi'Space (retrouvé par son numéro) pour
 * suivre sa commande et déclarer une panne.
 */
class CommandeParPartenaireService
{
    /**
     * @return array{commande: CommandePartenaire, approvisionnement: ?ApprovisionnementPartenaire}
     */
    public function creer(Partenaire $partenaire, Produit $produit, int $quantite, ?float $prixUnitaire, array $client, int $auteurId): array
    {
        if (! $produit->estVisibleALaVente()) {
            throw ValidationException::withMessages(['produit_id' => ["Ce produit n'est plus disponible."]]);
        }

        $prixCession = TarificationPartenaire::prixCession($partenaire, $produit);
        $offre = OffrePartenaire::where('partenaire_id', $partenaire->user_id)->where('produit_id', $produit->id)->first();
        $prix = round($prixUnitaire ?? (float) ($offre?->prix_revente ?? TarificationPartenaire::prixOrdispace($produit)));

        if ($prix < $prixCession) {
            throw ValidationException::withMessages(['prix_unitaire' => ['Le prix de vente ne peut pas être inférieur à votre prix de cession ('.number_format($prixCession, 0, ',', ' ').' F).']]);
        }

        // Lieu en texte libre : la commande est rattachée à la ville principale du partenaire.
        $villeId = $partenaire->localites()->orderBy('localites.id')->value('localites.id');
        if (! $villeId) {
            throw ValidationException::withMessages(['partenaire' => ["Aucune ville n'est rattachée à votre boutique : contactez Ordi'Space."]]);
        }

        $telephone = normaliser_telephone($client['telephone']);

        return DB::transaction(function () use ($partenaire, $produit, $quantite, $prix, $prixCession, $client, $telephone, $auteurId, $villeId) {
            $acheteur = User::where('telephone', $telephone)->where('type_utilisateur', ROLE_CLIENT)->first()
                ?? Client::creerCompteMinimal($client['nom'], null, $telephone);

            $commande = CommandePartenaire::create([
                'reference' => CommandePartenaire::genererReference(),
                'partenaire_id' => $partenaire->user_id,
                'client_id' => $acheteur->id,
                'localite_id' => $villeId,
                'mode_remise' => MODE_REMISE_LIVRAISON,
                'adresse_livraison' => $client['lieu'],
                'telephone_contact' => $telephone,
                'statut' => STATUT_COMMANDE_EN_ATTENTE,
                'montant_total' => $prix * $quantite,
            ]);
            $commande->lignes()->create([
                'produit_id' => $produit->id, 'quantite' => $quantite, 'prix_unitaire' => $prix,
                'prix_cession_unitaire' => $prixCession, 'duree_garantie_mois' => $produit->duree_garantie_mois,
            ]);
            SuiviCommandePartenaire::enregistrer($commande, 'commande_creee', "Commande enregistrée par le partenaire pour {$client['nom']}.", $auteurId, null, STATUT_COMMANDE_EN_ATTENTE);
            FilPartenaire::publierCommandeCreee($commande->load('lignes'));

            $enStock = (int) StockPartenaire::where('partenaire_id', $partenaire->user_id)->where('produit_id', $produit->id)->lockForUpdate()->value('quantite');

            if ($enStock >= $quantite) {
                self::valider($commande, $auteurId);

                return ['commande' => $commande, 'approvisionnement' => null];
            }

            $manquant = $quantite - $enStock;
            $appro = ApprovisionnementPartenaire::create([
                'reference' => ApprovisionnementPartenaire::genererReference(),
                'partenaire_id' => $partenaire->user_id,
                'commande_partenaire_id' => $commande->id,
                'statut' => STATUT_APPRO_EN_ATTENTE_PAIEMENT,
                'montant_total' => $prixCession * $manquant,
            ]);
            $appro->lignes()->create(['produit_id' => $produit->id, 'quantite' => $manquant, 'prix_cession_unitaire' => $prixCession]);

            return ['commande' => $commande, 'approvisionnement' => $appro];
        });
    }

    /**
     * "Payer" sur l'écran Confirmation : le partenaire indique avoir payé
     * Ordi'Space hors application. Le paiement reste à valider par l'Admin
     * (aucune validation automatique : rien ne transite par Wave).
     */
    public static function confirmerPaiementHorsApplication(ApprovisionnementPartenaire $appro): ApprovisionnementPartenaire
    {
        $appro = DB::transaction(function () use ($appro) {
            $a = ApprovisionnementPartenaire::whereKey($appro->id)->lockForUpdate()->first();
            if ($a->statut !== STATUT_APPRO_EN_ATTENTE_PAIEMENT) {
                throw ValidationException::withMessages(['statut' => ["Action impossible : l'approvisionnement est « {$a->statut} »."]]);
            }
            $a->update([
                'statut' => STATUT_APPRO_PAIEMENT_DECLARE,
                'mode_paiement' => 'wave',
                'reference_paiement' => 'Paiement hors application',
                'motif_refus' => null,
                'paiement_declare_le' => now(),
            ]);

            return $a;
        });

        $appro->load('partenaire');
        NotificationAdminService::notifierTousLesAdmins(
            'approvisionnement_partenaire',
            'Paiement partenaire à vérifier',
            "{$appro->partenaire->nom_boutique} confirme avoir payé {$appro->reference} (".number_format((float) $appro->montant_total, 0, ',', ' ').' F) hors application.'
        );

        return $appro;
    }

    /** Valide la commande et sort le stock (dans une transaction, stock suffisant vérifié). */
    public static function valider(CommandePartenaire $commande, ?int $auteurId): void
    {
        foreach ($commande->lignes as $ligne) {
            StockPartenaireService::mouvement($commande->partenaire_id, $ligne->produit_id, -$ligne->quantite, MOUVEMENT_STOCK_VENTE, [
                'commande_partenaire_id' => $commande->id, 'auteur_id' => $auteurId,
            ]);
        }
        $avant = $commande->statut;
        $commande->update(['statut' => STATUT_COMMANDE_VALIDEE, 'stock_sorti' => true, 'acceptee_le' => now()]);
        SuiviCommandePartenaire::enregistrer($commande, 'statut_change', 'Statut : Validée.', $auteurId, $avant, STATUT_COMMANDE_VALIDEE);
    }
}
