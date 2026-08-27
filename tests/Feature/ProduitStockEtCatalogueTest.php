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
}
