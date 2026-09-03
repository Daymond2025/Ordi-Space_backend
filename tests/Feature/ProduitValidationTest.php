<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class ProduitValidationTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_le_coordinateur_modifie_le_prix_d_un_produit_en_attente(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE, 'prix' => 100000]);

        $reponse = $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/prix", [
            'prix' => 120000,
        ]);

        $reponse->assertOk();
        $this->assertEquals(120000, $produit->fresh()->prix);
    }

    /**
     * Le menu d'actions (☰) permet de corriger le prix même après
     * publication — modifierPrix() n'est plus restreint par statut,
     * contrairement à valider() (cf. ProduitPolicy::modifierPrix()).
     */
    public function test_le_coordinateur_modifie_le_prix_d_un_produit_deja_valide(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_VALIDE, 'prix' => 100000]);

        $reponse = $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/prix", [
            'prix' => 120000,
        ]);

        $reponse->assertOk();
        $this->assertEquals(120000, $produit->fresh()->prix);
    }

    public function test_un_fournisseur_ne_peut_pas_modifier_le_prix_via_cet_endpoint(): void
    {
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE, 'prix' => 100000]);
        $proprietaire = \App\Models\User::findOrFail($produit->fournisseur_id);

        $this->actingAs($proprietaire)->patchJson("/api/v1/produits/{$produit->id}/prix", [
            'prix' => 120000,
        ])->assertForbidden();
    }

    public function test_le_coordinateur_publie_un_produit_avec_prix_de_vente_et_commissions(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE, 'prix' => 15000]);

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/publier", [
            'prix_vente' => 18000,
            'commission_agent' => 1500,
            'commission_apporteur' => 750,
        ]);

        $reponse->assertOk();
        $produit->refresh();
        $this->assertSame(STATUT_PRODUIT_VALIDE, $produit->statut_produit);
        $this->assertEquals(18000, $produit->prix_vente);
        $this->assertEquals(1500, $produit->commission_agent);
        $this->assertEquals(750, $produit->commission_apporteur);
        $this->assertEquals(15000, $produit->prix, 'le prix partenaire ne doit jamais être modifié par cet écran');
        $this->assertEquals(3000, $produit->commission_ordispace);
        $this->assertDatabaseHas('validations_produits', [
            'produit_id' => $produit->id, 'coordinateur_id' => $coordinateur->id, 'decision' => 'valide',
        ]);
    }

    public function test_publier_applique_les_valeurs_par_defaut_des_commissions(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE, 'prix' => 15000]);

        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/publier", [
            'prix_vente' => 18000,
        ])->assertOk();

        $produit->refresh();
        $this->assertEquals(1000, $produit->commission_agent);
        $this->assertEquals(750, $produit->commission_apporteur); // 25% de 3000
    }

    public function test_un_fournisseur_ne_peut_pas_publier(): void
    {
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE]);
        $proprietaire = \App\Models\User::findOrFail($produit->fournisseur_id);

        $this->actingAs($proprietaire)->postJson("/api/v1/produits/{$produit->id}/publier", [
            'prix_vente' => 20000,
        ])->assertForbidden();
    }

    public function test_le_coordinateur_supprime_un_produit_sans_commande_associee(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();

        $this->actingAs($coordinateur)->deleteJson("/api/v1/produits/{$produit->id}")->assertOk();
        $this->assertNull(\App\Models\Produit::find($produit->id));
    }

    public function test_le_coordinateur_ne_peut_pas_supprimer_un_produit_deja_commande(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();
        $ligne = $this->creerAchatLivre($client, $produit);

        $this->actingAs($coordinateur)->deleteJson("/api/v1/produits/{$produit->id}")->assertUnprocessable();
        $this->assertNotNull(\App\Models\Produit::find($produit->id));
    }

    public function test_un_fournisseur_ne_peut_pas_supprimer_un_produit(): void
    {
        $produit = $this->creerProduitPhysique();
        $proprietaire = \App\Models\User::findOrFail($produit->fournisseur_id);

        $this->actingAs($proprietaire)->deleteJson("/api/v1/produits/{$produit->id}")->assertForbidden();
    }
}
