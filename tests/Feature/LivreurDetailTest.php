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
 * GET /coordinateur/livreurs/{livreur} (profil + statistiques),
 * GET /coordinateur/livreurs/{livreur}/missions (historique livraisons),
 * GET /coordinateur/livraisons-disponibles (vivier pour l'assignation) —
 * écran détail livreur (Espace Coordinateur).
 */
class LivreurDetailTest extends TestCase
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

    /**
     * Commande + ligne + livraison prêtes, avec le statut de livraison et le
     * livreur souhaités — mêmes prérequis relationnels que creerAchatLivre()
     * (InteragitAvecApi) mais avec une adresse/livraison explicite.
     */
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

    public function test_le_detail_calcule_les_statistiques(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur(['disponible' => true]);

        $this->creerLivraison($livreur->id, STATUT_LIVRAISON_LIVREE);
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

        $autreLivraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_LIVREE);
        Paiement::create([
            'commande_id' => $autreLivraison->commande_id,
            'livreur_id' => $livreur->id,
            'montant' => 3000,
            'mode_paiement' => 'especes',
            'statut_paiement' => STATUT_PAIEMENT_EN_ATTENTE,
            'date_paiement' => now(),
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/coordinateur/livreurs/{$livreur->id}");

        $reponse->assertOk();
        $donnees = $reponse->json('data');
        $this->assertSame($livreur->id, $donnees['user_id']);
        $this->assertTrue($donnees['disponible']);
        $this->assertSame(5, $donnees['statistiques']['commandes_total']);
        $this->assertSame(3, $donnees['statistiques']['commandes_livrees']);
        $this->assertSame(1, $donnees['statistiques']['commandes_retournees']);
        // Seul le paiement confirmé (5000) compte, pas celui en_attente (3000).
        $this->assertEquals(5000, $donnees['statistiques']['gains_total_recu']);
        // Aucun des deux paiements n'a de date_depot : tout le confirmé (5000) est "non déposé".
        $this->assertEquals(5000, $donnees['statistiques']['gains_non_deposes']);
    }

    public function test_gains_non_deposes_exclut_le_cash_deja_reverse(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur();

        $livraisonA = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_LIVREE);
        Paiement::create([
            'commande_id' => $livraisonA->commande_id,
            'livreur_id' => $livreur->id,
            'montant' => 5000,
            'mode_paiement' => 'especes',
            'statut_paiement' => STATUT_PAIEMENT_CONFIRME,
            'date_paiement' => now(),
            'date_depot' => now(),
        ]);

        $livraisonB = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_LIVREE);
        Paiement::create([
            'commande_id' => $livraisonB->commande_id,
            'livreur_id' => $livreur->id,
            'montant' => 2000,
            'mode_paiement' => 'especes',
            'statut_paiement' => STATUT_PAIEMENT_CONFIRME,
            'date_paiement' => now(),
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/coordinateur/livreurs/{$livreur->id}");

        $donnees = $reponse->json('data');
        $this->assertEquals(7000, $donnees['statistiques']['gains_total_recu']);
        $this->assertEquals(2000, $donnees['statistiques']['gains_non_deposes']);
    }

    public function test_commandes_retournees_inclut_le_retour_necessaire(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur();

        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_ECHOUEE);
        $livraison->update(['retour_necessaire' => true, 'statut_retour' => STATUT_RETOUR_LIVRAISON_EN_COURS]);
        $this->creerLivraison($livreur->id, STATUT_LIVRAISON_LIVREE);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/coordinateur/livreurs/{$livreur->id}");

        $this->assertSame(1, $reponse->json('data.statistiques.commandes_retournees'));
    }

    public function test_les_missions_ne_montrent_que_les_livraisons_de_ce_livreur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur();
        $autreLivreur = $this->creerLivreur();

        $mission = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_LIVREE);
        $this->creerLivraison($autreLivreur->id, STATUT_LIVRAISON_LIVREE);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/coordinateur/livreurs/{$livreur->id}/missions");

        $reponse->assertOk();
        $missions = $reponse->json('data');
        $this->assertCount(1, $missions);
        $this->assertSame($mission->commande_id, $missions[0]['commande_id']);
        $this->assertSame('Laptop Test', $missions[0]['nom_produit']);
    }

    public function test_les_missions_exposent_zone_paiement_et_retour(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur();

        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_ECHOUEE);
        $livraison->update(['retour_necessaire' => true, 'statut_retour' => STATUT_RETOUR_LIVRAISON_EN_COURS]);

        Paiement::create([
            'commande_id' => $livraison->commande_id,
            'livreur_id' => $livreur->id,
            'montant' => 5000,
            'mode_paiement' => 'especes',
            'statut_paiement' => STATUT_PAIEMENT_CONFIRME,
            'date_paiement' => now(),
            'date_limite_depot' => now()->addHours(24),
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/coordinateur/livreurs/{$livreur->id}/missions");

        $ligne = $reponse->json('data')[0];
        $this->assertSame('Cocody', $ligne['zone_destination']);
        $this->assertSame('Fournisseur Test', $ligne['nom_fournisseur']);
        $this->assertTrue($ligne['retour_necessaire']);
        $this->assertSame(STATUT_RETOUR_LIVRAISON_EN_COURS, $ligne['statut_retour']);
        $this->assertSame('especes', $ligne['paiement']['mode_paiement']);
        $this->assertNull($ligne['paiement']['date_depot']);
    }

    public function test_le_vivier_ne_montre_que_les_livraisons_non_affectees(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur();

        $disponible = $this->creerLivraison(null, STATUT_LIVRAISON_EN_ATTENTE_LIVREUR);
        $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);
        $this->creerLivraison($livreur->id, STATUT_LIVRAISON_LIVREE);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/livraisons-disponibles');

        $reponse->assertOk();
        $livraisons = $reponse->json('data');
        $this->assertCount(1, $livraisons);
        $this->assertSame($disponible->commande_id, $livraisons[0]['commande_id']);
        $this->assertSame('Laptop Test', $livraisons[0]['nom_produit']);
        $this->assertSame('Cocody', $livraisons[0]['zone_destination']);
        $this->assertArrayHasKey('frais_livraison', $livraisons[0]);
        $this->assertArrayHasKey('zone_depart', $livraisons[0]);
        $this->assertArrayHasKey('photo', $livraisons[0]);
    }

    public function test_le_vivier_se_filtre_par_fournisseur(): void
    {
        $coordinateur = $this->creerCoordinateur();

        $produitA = $this->creerProduitPhysique();
        $produitB = $this->creerProduitPhysique();

        $livraisonA = $this->creerLivraison(null, STATUT_LIVRAISON_EN_ATTENTE_LIVREUR, $produitA);
        $this->creerLivraison(null, STATUT_LIVRAISON_EN_ATTENTE_LIVREUR, $produitB);

        $reponse = $this->actingAs($coordinateur)->getJson(
            '/api/v1/coordinateur/livraisons-disponibles?fournisseur_id='.$produitA->fournisseur_id
        );

        $reponse->assertOk();
        $livraisons = $reponse->json('data');
        $this->assertCount(1, $livraisons);
        $this->assertSame($livraisonA->commande_id, $livraisons[0]['commande_id']);
    }

    public function test_un_commercial_ne_peut_pas_consulter_le_detail(): void
    {
        $commercial = $this->creerCommercial();
        $livreur = $this->creerLivreur();

        $this->actingAs($commercial)->getJson("/api/v1/coordinateur/livreurs/{$livreur->id}")->assertForbidden();
        $this->actingAs($commercial)->getJson("/api/v1/coordinateur/livreurs/{$livreur->id}/missions")->assertForbidden();
        $this->actingAs($commercial)->getJson('/api/v1/coordinateur/livraisons-disponibles')->assertForbidden();
    }
}
