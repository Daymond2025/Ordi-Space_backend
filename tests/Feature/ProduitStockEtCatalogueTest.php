<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class ProduitStockEtCatalogueTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Régression : `produits` et `produits/{id}` sont des routes publiques
     * (hors auth:sanctum) — actingAs() masque un bug où $request->user() ne
     * résout jamais le Bearer token sur ces routes précises (guard par défaut
     * ≠ sanctum sans le middleware), faisant échouer silencieusement tout
     * accès "élevé" du coordinateur en conditions réelles malgré des tests
     * actingAs() vert. Ces deux tests utilisent un vrai jeton Sanctum via
     * withHeader(), comme le ferait un vrai client HTTP.
     */
    public function test_avec_un_vrai_jeton_le_coordinateur_voit_un_produit_en_attente_via_show(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $token = $coordinateur->createToken('test')->plainTextToken;
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/produits/{$produit->id}")
            ->assertOk();
    }

    public function test_avec_un_vrai_jeton_statut_tous_leve_la_restriction_pour_le_coordinateur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $token = $coordinateur->createToken('test')->plainTextToken;
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE]);

        $reponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/produits?statut=tous');

        $reponse->assertOk();
        $ids = collect($reponse->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($produit->id));
    }

    public function test_statut_tous_leve_la_restriction_pour_le_coordinateur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $this->creerProduitPhysique(); // statut_produit = valide, hors file d'attente par défaut

        $defaut = $this->actingAs($coordinateur)->getJson('/api/v1/produits');
        $defaut->assertOk();
        $this->assertCount(0, $defaut->json('data.data'));

        $tous = $this->actingAs($coordinateur)->getJson('/api/v1/produits?statut=tous');
        $tous->assertOk();
        $this->assertCount(1, $tous->json('data.data'));
    }

    public function test_filtre_indisponible_se_base_sur_le_stock(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $enStock = $this->creerProduitPhysique(['quantite_stock' => 5]);
        $rupture = $this->creerProduitPhysique(['quantite_stock' => 0]);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/produits?statut=indisponible');

        $reponse->assertOk();
        $ids = collect($reponse->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($rupture->id));
        $this->assertFalse($ids->contains($enStock->id));
    }

    public function test_filtre_fournisseur_id_est_universel(): void
    {
        $produitA = $this->creerProduitPhysique();
        $produitB = $this->creerProduitPhysique();

        $reponse = $this->getJson("/api/v1/produits?fournisseur_id={$produitA->fournisseur_id}");

        $reponse->assertOk();
        $ids = collect($reponse->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($produitA->id));
        $this->assertFalse($ids->contains($produitB->id));
    }

    public function test_le_coordinateur_modifie_le_stock_de_n_importe_quel_produit(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5]);

        $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/stock", [
            'quantite_stock' => 0,
        ])->assertOk();

        $this->assertEquals(0, $produit->fresh()->quantite_stock);
    }

    public function test_le_fournisseur_modifie_son_stock_mais_pas_celui_d_un_autre(): void
    {
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5]);
        $proprietaire = User::findOrFail($produit->fournisseur_id);
        $autreFournisseur = $this->creerFournisseur();

        $this->actingAs($proprietaire)->patchJson("/api/v1/produits/{$produit->id}/stock", [
            'quantite_stock' => 8,
        ])->assertOk();
        $this->assertEquals(8, $produit->fresh()->quantite_stock);

        $this->actingAs($autreFournisseur)->patchJson("/api/v1/produits/{$produit->id}/stock", [
            'quantite_stock' => 1,
        ])->assertForbidden();
    }

    public function test_un_client_ne_peut_pas_modifier_le_stock(): void
    {
        $produit = $this->creerProduitPhysique();
        $client = $this->creerClient();

        $this->actingAs($client)->patchJson("/api/v1/produits/{$produit->id}/stock", [
            'quantite_stock' => 1,
        ])->assertForbidden();
    }

    public function test_la_recherche_filtre_par_nom_produit(): void
    {
        $laptop = $this->creerProduitPhysique(['nom_produit' => 'HP 840 G5', 'statut_produit' => STATUT_PRODUIT_VALIDE]);
        $autre = $this->creerProduitPhysique(['nom_produit' => 'Dell Latitude', 'statut_produit' => STATUT_PRODUIT_VALIDE]);

        $reponse = $this->getJson('/api/v1/produits?recherche=hp+840');

        $reponse->assertOk();
        $ids = collect($reponse->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($laptop->id));
        $this->assertFalse($ids->contains($autre->id));
    }

    public function test_statistiques_catalogue_pour_le_coordinateur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $this->creerProduitPhysique(['quantite_stock' => 5]);
        $this->creerProduitPhysique(['quantite_stock' => 0]);
        $this->creerProduitPhysique(['quantite_stock' => 3, 'est_booste' => true]);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/produits/statistiques');

        $reponse->assertOk();
        $this->assertSame(3, $reponse->json('data.total'));
        $this->assertSame(1, $reponse->json('data.boostes'));
        $this->assertSame(1, $reponse->json('data.indisponibles'));
    }

    public function test_statistiques_catalogue_scopees_pour_un_fournisseur(): void
    {
        $produitA = $this->creerProduitPhysique();
        $this->creerProduitPhysique(); // un autre fournisseur
        $proprietaire = User::findOrFail($produitA->fournisseur_id);

        $reponse = $this->actingAs($proprietaire)->getJson('/api/v1/produits/statistiques');

        $reponse->assertOk();
        $this->assertSame(1, $reponse->json('data.total'));
    }

    public function test_le_detail_produit_renvoie_les_caracteristiques_et_les_cadeaux(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique([
            'statut_produit' => STATUT_PRODUIT_VALIDE,
            'processeur' => 'Intel Core i7-1260P',
            'memoire_ram' => '16GB LPDDR5-5200',
            'stockage' => '512GB SSD',
            'taille' => '14" Pouces',
            'systeme_exploitation' => 'Windows 11 Pro 64',
            'carte_graphique' => 'Intel',
            'cadeaux' => ['Souris', 'Sacs'],
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}");

        $reponse->assertOk();
        $reponse->assertJsonPath('data.processeur', 'Intel Core i7-1260P');
        $reponse->assertJsonPath('data.memoire_ram', '16GB LPDDR5-5200');
        $reponse->assertJsonPath('data.cadeaux', ['Souris', 'Sacs']);
    }
}
