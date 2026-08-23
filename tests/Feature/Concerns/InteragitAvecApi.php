<?php

namespace Tests\Feature\Concerns;

use App\Models\Administrateur;
use App\Models\CanalVente;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Commercial;
use App\Models\Fournisseur;
use App\Models\Garantie;
use App\Models\LigneCommande;
use App\Models\Produit;
use App\Models\User;

trait InteragitAvecApi
{
    protected function creerClient(array $attributs = []): User
    {
        $user = User::factory()->create(array_merge(['type_utilisateur' => ROLE_CLIENT], $attributs));
        $user->assignRole(ROLE_CLIENT);
        Client::create(['user_id' => $user->id, 'code_parrainage' => 'TEST-'.$user->id]);

        return $user;
    }

    protected function creerAdmin(array $attributs = []): User
    {
        $user = User::factory()->create(array_merge(['type_utilisateur' => ROLE_ADMINISTRATEUR], $attributs));
        $user->assignRole(ROLE_ADMINISTRATEUR);
        Administrateur::create(['user_id' => $user->id]);

        return $user;
    }

    /**
     * Commercial système utilisé par CommandeController::resoudreCommercial()
     * pour toute commande passée par un client lui-même (pas de commercial
     * humain impliqué) — requis pour que POST /commandes fonctionne en test.
     */
    protected function creerAgentIa(): User
    {
        $user = User::where('email', AGENT_IA_EMAIL)->first();
        if ($user) {
            return $user;
        }

        $user = User::factory()->create(['type_utilisateur' => ROLE_COMMERCIAL, 'email' => AGENT_IA_EMAIL]);
        Commercial::create(['user_id' => $user->id, 'type_commercial' => TYPE_COMMERCIAL_IA, 'nom_modele_ia' => AGENT_IA_NOM_MODELE]);
        $user->assignRole(ROLE_COMMERCIAL);

        return $user;
    }

    protected function creerProduitPhysique(array $attributs = []): Produit
    {
        $fournisseur = User::factory()->create(['type_utilisateur' => 'fournisseur']);
        Fournisseur::create(['user_id' => $fournisseur->id, 'nom_entreprise' => 'Fournisseur Test']);

        $categorie = Categorie::firstOrCreate(['nom_categorie' => 'Ordinateurs portables']);

        return Produit::create(array_merge([
            'fournisseur_id' => $fournisseur->id,
            'categorie_id' => $categorie->id,
            'nom_produit' => 'Laptop Test',
            'prix' => 500000,
            'quantite_stock' => 10,
            'statut_produit' => STATUT_PRODUIT_VALIDE,
            'type_livraison' => 'physique',
            'duree_garantie_mois' => 12,
        ], $attributs));
    }

    /**
     * Achat "livré" complet (commande + ligne + garantie) prêt pour les
     * scénarios GarantiX / mes-achats — mêmes prérequis relationnels que la
     * vraie commande (commercial, canal de vente).
     */
    protected function creerAchatLivre(User $client, ?Produit $produit = null): LigneCommande
    {
        $produit ??= $this->creerProduitPhysique();

        $commercial = User::factory()->create(['type_utilisateur' => 'commercial']);
        Commercial::create(['user_id' => $commercial->id, 'type_commercial' => 'humain']);

        $canal = CanalVente::firstOrCreate(['nom_canal' => 'Boutique en ligne']);

        $commande = Commande::create([
            'client_id' => $client->id,
            'commercial_id' => $commercial->id,
            'canal_vente_id' => $canal->id,
            'statut_commande' => 'livree',
            'montant_total' => $produit->prix,
            'date_commande' => now(),
        ]);

        $ligne = LigneCommande::create([
            'commande_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 1,
            'prix_unitaire' => $produit->prix,
        ]);

        Garantie::create([
            'ligne_commande_id' => $ligne->id,
            'date_debut' => now(),
            'date_fin' => now()->addYear(),
            'type_garantie' => 'ordispace',
        ]);

        return $ligne->fresh(['produit', 'commande', 'garantie']);
    }
}
