<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Même vue fusionnée (achats externes + crédits portefeuille) que l'onglet
 * "Paiement" du fournisseur, mais consultée par le Coordinateur/Admin sur la
 * fiche d'UN fournisseur — GET /fournisseurs/{fournisseur}/paiements.
 * Voir FournisseurController::paiements()/paiementsPour().
 */
class PaiementsAdminFournisseurTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_ladmin_voit_les_achats_externes_et_credits_dun_fournisseur_precis(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseurUser = User::findOrFail($produit->fournisseur_id);
        $admin = $this->creerAdmin();

        $achat = $this->actingAs($fournisseurUser)->postJson("/api/v1/produits/{$produit->id}/achats-externes", [
            'montant_vente' => 20000,
            'date_vente' => now()->toDateString(),
        ])->json('data');

        $reponse = $this->actingAs($admin)->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}/paiements");
        $reponse->assertOk();

        $items = $reponse->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('achat_externe', $items[0]['type']);
        $this->assertEquals($achat['commission_due'], $items[0]['montant']);
        $this->assertEquals($achat['commission_due'], $reponse->json('data.total_a_payer'));
    }

    public function test_le_coordinateur_peut_aussi_consulter_cette_route(): void
    {
        $produit = $this->creerProduitPhysique();
        $coordinateur = $this->creerCoordinateur();

        $this->actingAs($coordinateur)
            ->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}/paiements")
            ->assertOk();
    }

    public function test_un_fournisseur_ne_peut_pas_consulter_cette_route_admin(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseurUser = User::findOrFail($produit->fournisseur_id);

        $this->actingAs($fournisseurUser)
            ->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}/paiements")
            ->assertForbidden();
    }

    public function test_les_paiements_dun_fournisseur_najoutent_pas_ceux_dun_autre(): void
    {
        $produitA = $this->creerProduitPhysique();
        $produitB = $this->creerProduitPhysique();
        $fournisseurA = User::findOrFail($produitA->fournisseur_id);
        $admin = $this->creerAdmin();

        $this->actingAs($fournisseurA)->postJson("/api/v1/produits/{$produitA->id}/achats-externes", [
            'montant_vente' => 20000,
            'date_vente' => now()->toDateString(),
        ])->assertCreated();

        $reponse = $this->actingAs($admin)->getJson("/api/v1/fournisseurs/{$produitB->fournisseur_id}/paiements");
        $reponse->assertOk();
        $this->assertCount(0, $reponse->json('data.items'));
    }
}
