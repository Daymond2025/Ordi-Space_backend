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
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * GET /admin/livreurs/tableau-de-bord — vue d'ensemble de l'espace Livreurs
 * côté Admin (Admin\LivreurController::tableauDeBord()), même esprit que
 * Admin\ClientController::tableauDeBord().
 */
class AdminLivreurTableauDeBordTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerLivreur(array $attributsUser = [], array $attributsLivreur = []): User
    {
        $user = User::factory()->create(array_merge(['type_utilisateur' => ROLE_LIVREUR], $attributsUser));
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(array_merge(['user_id' => $user->id, 'type_vehicule' => 'moto', 'disponible' => true], $attributsLivreur));

        return $user;
    }

    private function creerLivraisonPourLivreur(?User $livreur, string $statutLivraison): Livraison
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();
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
            'livreur_id' => $livreur?->id,
            'adresse_id' => $adresse->id,
            'statut_livraison' => $statutLivraison,
        ]);
    }

    public function test_ladmin_voit_le_tableau_de_bord_livreurs(): void
    {
        $admin = $this->creerAdmin();

        $livreurActif = $this->creerLivreur(['statut_compte' => STATUT_COMPTE_ACTIF], ['disponible' => true, 'type_vehicule' => 'moto']);
        $this->creerLivreur(['statut_compte' => STATUT_COMPTE_SUSPENDU], ['disponible' => false, 'type_vehicule' => 'voiture']);

        $this->creerLivraisonPourLivreur(null, STATUT_LIVRAISON_EN_ATTENTE_LIVREUR);
        $this->creerLivraisonPourLivreur($livreurActif, STATUT_LIVRAISON_EN_COURS);
        $livraisonLivree = $this->creerLivraisonPourLivreur($livreurActif, STATUT_LIVRAISON_LIVREE);

        Paiement::create([
            'commande_id' => $livraisonLivree->commande_id,
            'livreur_id' => $livreurActif->id,
            'montant' => 1500,
            'mode_paiement' => MODE_PAIEMENT_ESPECES,
            'statut_paiement' => STATUT_PAIEMENT_CONFIRME,
            'date_paiement' => now(),
            'date_limite_depot' => now()->subDay(),
            'date_depot' => null,
        ]);

        $reponse = $this->actingAs($admin)->getJson('/api/v1/admin/livreurs/tableau-de-bord');

        $reponse->assertOk();
        $donnees = $reponse->json('data');

        $this->assertSame(2, $donnees['livreurs']['total']);
        $this->assertSame(1, $donnees['livreurs']['actifs']);
        $this->assertSame(1, $donnees['livreurs']['suspendus']);
        $this->assertSame(1, $donnees['livreurs']['disponibles_maintenant']);
        $this->assertSame(1, $donnees['types_vehicule']['moto']);
        $this->assertSame(1, $donnees['types_vehicule']['voiture']);
        $this->assertGreaterThanOrEqual(1, $donnees['missions']['vivier']);
        $this->assertGreaterThanOrEqual(1, $donnees['missions']['en_cours']);
        $this->assertGreaterThanOrEqual(1, $donnees['missions']['livrees']);
        $this->assertEquals(1500.0, $donnees['paiements_cod']['non_depose']);
        $this->assertSame(1, $donnees['paiements_cod']['en_retard']);
    }

    public function test_un_non_administrateur_ne_peut_pas_consulter_le_tableau_de_bord_livreurs(): void
    {
        $fournisseur = $this->creerFournisseur();

        $this->actingAs($fournisseur)
            ->getJson('/api/v1/admin/livreurs/tableau-de-bord')
            ->assertForbidden();
    }
}
