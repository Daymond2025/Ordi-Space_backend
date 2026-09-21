<?php

namespace App\Services;

use App\Models\Adresse;
use App\Models\CanalVente;
use App\Models\Client;
use App\Models\Commande;
use App\Models\FraisLivraisonProduit;
use App\Models\JournalAudit;
use App\Models\LienAffilie;
use App\Models\LigneCommande;
use App\Models\Livraison;
use App\Models\Localite;
use App\Models\Message;
use App\Models\NotificationOrdispace;
use App\Models\Produit;
use App\Models\User;
use App\Models\VenteBoutique;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Commande passée par un acheteur SANS compte depuis la page publique d'un
 * lien de vente, d'une vitrine ou d'un QR (dossier page_commande). Même
 * résultat que POST /commandes — commande "en_attente" à valider par le
 * Coordinateur/Admin, livraison en préparation, stock décrémenté, message
 * "Nouvelle commande" dans la discussion du produit — plus le rattachement à
 * la personne qui a partagé le lien (VenteBoutique, commission figée).
 *
 * L'acheteur n'a pas de compte : un client minimal (nom + téléphone) est créé,
 * ou retrouvé par son numéro. Le paiement se fait à la livraison, comme pour
 * toute commande — rien à payer en ligne.
 */
class CommandePubliqueService
{
    /**
     * Ce que la commande coûterait en livraison, ou une erreur claire si elle est impossible
     * (produit indisponible, stock insuffisant, commune non desservie). Sert à refuser AVANT de
     * faire payer la confirmation ; creer() refait ces contrôles, verrou de stock compris.
     */
    public function verifierDisponibilite(Produit $produit, int $quantite, int $localiteId): float
    {
        if (! $produit->estVisibleALaVente() || $produit->estNumerique()) {
            throw ValidationException::withMessages(['produit' => ["Ce produit n'est pas disponible à la commande."]]);
        }

        if ($produit->quantite_stock < $quantite) {
            throw ValidationException::withMessages([
                'quantite' => [$produit->quantite_stock > 0 ? "Il ne reste que {$produit->quantite_stock} exemplaire(s) en stock." : 'Ce produit est en rupture de stock.'],
            ]);
        }

        $localite = Localite::findOrFail($localiteId);
        $frais = FraisLivraisonProduit::where('produit_id', $produit->id)->where('localite_id', $localite->id)->value('montant');
        if ($frais === null) {
            throw ValidationException::withMessages(['localite_id' => ["La livraison de ce produit n'est pas proposée vers « {$localite->nom} »."]]);
        }

        return (float) $frais;
    }

    /**
     * @param array{nom: string, prenom?: ?string, telephone: string, localite_id: int, adresse: string, notes?: ?string} $acheteur
     */
    public function creer(User $vendeur, Produit $produit, int $quantite, array $acheteur, string $source, ?LienAffilie $lien = null): Commande
    {
        $agentId = User::where('email', AGENT_IA_EMAIL)->value('id');
        abort_unless($agentId, 500, "Agent IA système introuvable — relancez le seeder.");

        $telephone = normaliser_numero_ci($acheteur['telephone']);

        $commande = DB::transaction(function () use ($vendeur, $produit, $quantite, $acheteur, $source, $lien, $agentId, $telephone) {
            $produit = Produit::whereKey($produit->id)->where('statut_produit', STATUT_PRODUIT_VALIDE)->lockForUpdate()->first();

            if (! $produit || $produit->estNumerique()) {
                throw ValidationException::withMessages(['produit' => ["Ce produit n'est pas disponible à la commande."]]);
            }

            if ($produit->quantite_stock < $quantite) {
                throw ValidationException::withMessages([
                    'quantite' => [$produit->quantite_stock > 0 ? "Il ne reste que {$produit->quantite_stock} exemplaire(s) en stock." : 'Ce produit est en rupture de stock.'],
                ]);
            }

            $localite = Localite::findOrFail($acheteur['localite_id']);
            $frais = FraisLivraisonProduit::where('produit_id', $produit->id)->where('localite_id', $localite->id)->value('montant');
            if ($frais === null) {
                throw ValidationException::withMessages(['localite_id' => ["La livraison de ce produit n'est pas proposée vers « {$localite->nom} »."]]);
            }

            $client = $this->trouverOuCreerClient($acheteur, $telephone);

            $adresse = Adresse::create([
                'client_id' => $client->id,
                'libelle' => 'Livraison',
                'rue' => $acheteur['adresse'],
                'ville' => $localite->type === TYPE_LOCALITE_COMMUNE_ABIDJAN ? 'Abidjan' : $localite->nom,
                'pays' => "Côte d'Ivoire",
                'localite_id' => $localite->id,
            ]);

            // prix_vente (fixé à la publication) est ce que paie le client ;
            // prix_partenaire_unitaire n'est figé que pour les produits passés
            // par le nouvel écran de publication — même règle que POST /commandes.
            $prixVente = $produit->prix_vente ?? $produit->prix;

            $commande = Commande::create([
                'client_id' => $client->id,
                'commercial_id' => $agentId,
                'canal_vente_id' => CanalVente::where('nom_canal', $source === 'whatsapp' ? 'WhatsApp' : CANAL_VENTE_BOUTIQUE_APPLICATION)->value('id'),
                'livraison_gratuite_appliquee' => false,
                'statut_commande' => STATUT_COMMANDE_EN_ATTENTE,
                'montant_total' => $prixVente * $quantite,
                'montant_remise' => 0,
                'frais_livraison' => (float) $frais,
                'notes' => $acheteur['notes'] ?? null,
                'date_commande' => now(),
            ]);

            LigneCommande::create([
                'commande_id' => $commande->id,
                'produit_id' => $produit->id,
                'quantite' => $quantite,
                'prix_unitaire' => $prixVente,
                'prix_partenaire_unitaire' => $produit->prix_vente !== null ? $produit->prix : null,
            ]);
            $produit->decrement('quantite_stock', $quantite);

            Livraison::create([
                'commande_id' => $commande->id,
                'adresse_id' => $adresse->id,
                'statut_livraison' => STATUT_LIVRAISON_EN_PREPARATION,
            ]);

            VenteBoutique::enregistrer($vendeur, $commande, $source, $lien);

            // Instantané "Nouvelle commande" dans la discussion du produit
            // (fil du Coordinateur), comme pour une commande passée dans l'app.
            Message::create([
                'produit_id' => $produit->id,
                'commande_id' => $commande->id,
                'auteur_id' => $agentId,
                'type' => TYPE_MESSAGE_COMMANDE_CREEE,
                'date_envoi' => now(),
                'donnees' => $commande->versApercu(),
            ]);

            return $commande;
        });

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_COMMANDE_CREEE,
            'commande',
            $source === 'manuelle'
                ? "Commande de {$commande->montant_total} CFA saisie par un livreur pour son client (n°{$commande->id})."
                : "Commande de {$commande->montant_total} CFA passée depuis un lien de vente, confirmation payée (n°{$commande->id}).",
            commandeId: $commande->id,
            acteurId: $vendeur->id,
        );

        // Une commande saisie par le livreur lui-même n'a rien à lui apprendre : il vient de l'envoyer.
        if ($source !== 'manuelle') {
            NotificationOrdispace::create([
                'user_id' => $vendeur->id,
                'type_notification' => 'vente_boutique',
                'titre' => 'Nouvelle commande',
                'contenu' => "Une commande n°{$commande->id} vient d'être passée via ton lien de vente. Elle attend la validation de l'équipe.",
                'lu' => false,
                'date_envoi' => now(),
            ]);
        }

        return $commande;
    }

    /** Client existant (même numéro) ou compte minimal — jamais un compte non-client réutilisé. */
    private function trouverOuCreerClient(array $acheteur, string $telephone): User
    {
        $existant = User::where('telephone', $telephone)->first();

        if ($existant) {
            if ($existant->type_utilisateur !== ROLE_CLIENT) {
                throw ValidationException::withMessages(['telephone' => ['Ce numéro ne peut pas être utilisé pour commander.']]);
            }

            return $existant;
        }

        return Client::creerCompteMinimal($acheteur['nom'], $acheteur['prenom'] ?? null, $telephone);
    }
}
