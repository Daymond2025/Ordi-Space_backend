<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class ProduitBoosteTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_le_coordinateur_peut_booster_un_produit(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();

        $reponse = $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/booster", [
            'est_booste' => true,
        ]);

        $reponse->assertOk();
        $this->assertDatabaseHas('produits', ['id' => $produit->id, 'est_booste' => true]);
    }

    public function test_un_fournisseur_ne_peut_pas_booster_son_propre_produit(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);

        $this->actingAs($fournisseur)->patchJson("/api/v1/produits/{$produit->id}/booster", [
            'est_booste' => true,
        ])->assertForbidden();
    }

    public function test_le_filtre_booste_respecte_la_visibilite_publique(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produitBoosteEtValide = $this->creerProduitPhysique(['nom_produit' => 'Boosté valide', 'est_booste' => true]);
        $this->creerProduitPhysique(['nom_produit' => 'Boosté en attente', 'est_booste' => true, 'statut_produit' => STATUT_PRODUIT_EN_ATTENTE]);

        $reponsePublique = $this->getJson('/api/v1/produits?booste=1');

        $reponsePublique->assertOk();
        $noms = collect($reponsePublique->json('data.data'))->pluck('nom_produit');
        $this->assertTrue($noms->contains('Boosté valide'));
        $this->assertFalse($noms->contains('Boosté en attente'));

        $this->assertNotNull($coordinateur);
        $this->assertNotNull($produitBoosteEtValide);
    }
}
