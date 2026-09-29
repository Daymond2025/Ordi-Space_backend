<?php

namespace Tests\Feature;

use App\Models\Adresse;
use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\LigneCommande;
use App\Models\Livraison;
use App\Models\Livreur;
use App\Models\Localite;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * GET /fournisseur/moi/livreurs/{livreur} — feuille détail livreur (onglet
 * "Livreurs", app Fournisseur), distincte de GET /coordinateur/livreurs/
 * {livreur} (LivreurDetailTest) : mêmes statistiques calculées, mais sans
 * les `documents` d'identité et accessible au rôle fournisseur.
 */
class LivreurDetailPourFournisseurTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerLivreur(array $attributsLivreur = []): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(array_merge(['user_id' => $user->id], $attributsLivreur));

        return $user;
    }

    private function creerLivraison(?int $livreurId, string $statutLivraison, ?Produit $produit = null): Livraison
    {
        $client = $this->creerClient();
        $produit ??= $this->creerProduitPhysique();
        $commercial = $this->creerAgentIa();
        $canal = CanalVente::firstOrCreate(['nom_canal' => 'Boutique en ligne']);
        $localite = Localite::firstOrCreate(['nom' => 'Cocody'], ['type' => 'commune_abidjan']);

        $commande = Commande::create([
            'client_id' => $client->id,
            'commercial_id' => $commercial->id,
            'canal_vente_id' => $canal->id,
            'statut_commande' => STATUT_COMMANDE_EN_LIVRAISON,
            'montant_total' => $produit->prix,
            'date_commande' => now(),
        ]);

        LigneCommande::create([
            'commande_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 1,
            'prix_unitaire' => $produit->prix,
        ]);

        $adresse = Adresse::create([
            'client_id' => $client->id,
            'rue' => 'Rue Test',
            'ville' => 'Abidjan',
            'pays' => "Côte d'Ivoire",
            'localite_id' => $localite->id,
        ]);

        return Livraison::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreurId,
            'adresse_id' => $adresse->id,
            'statut_livraison' => $statutLivraison,
        ]);
    }

    public function test_le_fournisseur_voit_le_profil_et_les_statistiques(): void
    {
        $fournisseur = $this->creerFournisseur();
        $livreur = $this->creerLivreur(['disponible' => true]);

        $this->creerLivraison($livreur->id, STATUT_LIVRAISON_LIVREE);
        $livraisonEchouee = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_ECHOUEE);
        $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        Paiement::create([
            'commande_id' => $livraisonEchouee->commande_id,
            'livreur_id' => $livreur->id,
            'montant' => 5000,
            'mode_paiement' => 'especes',
            'statut_paiement' => STATUT_PAIEMENT_CONFIRME,
            'date_paiement' => now(),
        ]);

        $reponse = $this->actingAs($fournisseur)->getJson("/api/v1/fournisseur/moi/livreurs/{$livreur->id}");

        $reponse->assertOk();
        $donnees = $reponse->json('data');
        $this->assertSame($livreur->id, $donnees['user_id']);
        $this->assertTrue($donnees['disponible']);
        $this->assertSame(3, $donnees['statistiques']['commandes_total']);
        $this->assertSame(1, $donnees['statistiques']['commandes_livrees']);
        $this->assertSame(1, $donnees['statistiques']['commandes_retournees']);
        $this->assertEquals(5000, $donnees['statistiques']['gains_total_recu']);
        $this->assertArrayHasKey('photo', $donnees);
        // Documents d'identité réservés au Coordinateur/Admin, pas exposés ici.
        $this->assertArrayNotHasKey('documents', $donnees);
    }

    public function test_un_livreur_ne_peut_pas_consulter_cette_route(): void
    {
        $autreLivreur = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $autreLivreur->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $autreLivreur->id]);
        $livreur = $this->creerLivreur();

        $this->actingAs($autreLivreur)->getJson("/api/v1/fournisseur/moi/livreurs/{$livreur->id}")->assertForbidden();
    }
}
