<?php

namespace Tests\Feature;

use App\Models\LienAffilie;
use App\Models\Livreur;
use App\Models\Produit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * POST /boutique/produits/{produit}/lien — le livreur clique "Vendre ce
 * produit" (écran Boutique) et obtient un lien affilié à partager.
 */
class BoutiqueLienAffilieTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerLivreur(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id]);

        return $user;
    }

    public function test_le_livreur_genere_un_lien_affilie_pour_un_produit(): void
    {
        $livreur = $this->creerLivreur();
        $produit = $this->creerProduitPhysique();

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/boutique/produits/{$produit->id}/lien");

        $reponse->assertOk();
        $code = $reponse->json('data.code');
        $this->assertNotEmpty($code);
        $this->assertStringEndsWith("/boutique/produit/{$code}", $reponse->json('data.url'));
        $this->assertDatabaseHas('liens_affilies', [
            'produit_id' => $produit->id,
            'livreur_id' => $livreur->id,
            'code' => $code,
        ]);
    }

    public function test_recliquer_renvoie_toujours_le_meme_lien(): void
    {
        $livreur = $this->creerLivreur();
        $produit = $this->creerProduitPhysique();

        $premiere = $this->actingAs($livreur)->postJson("/api/v1/boutique/produits/{$produit->id}/lien");
        $seconde = $this->actingAs($livreur)->postJson("/api/v1/boutique/produits/{$produit->id}/lien");

        $this->assertSame($premiere->json('data.code'), $seconde->json('data.code'));
        $this->assertSame(1, LienAffilie::where('produit_id', $produit->id)->where('livreur_id', $livreur->id)->count());
    }

    public function test_deux_livreurs_ont_des_liens_differents_pour_le_meme_produit(): void
    {
        $livreurA = $this->creerLivreur();
        $livreurB = $this->creerLivreur();
        $produit = $this->creerProduitPhysique();

        $reponseA = $this->actingAs($livreurA)->postJson("/api/v1/boutique/produits/{$produit->id}/lien");
        $reponseB = $this->actingAs($livreurB)->postJson("/api/v1/boutique/produits/{$produit->id}/lien");

        $this->assertNotSame($reponseA->json('data.code'), $reponseB->json('data.code'));
    }

    public function test_un_non_livreur_ne_peut_pas_generer_de_lien(): void
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();

        $this->actingAs($client)
            ->postJson("/api/v1/boutique/produits/{$produit->id}/lien")
            ->assertForbidden();
    }

    public function test_on_ne_peut_pas_generer_de_lien_pour_un_produit_non_valide(): void
    {
        $livreur = $this->creerLivreur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE]);

        $this->actingAs($livreur)
            ->postJson("/api/v1/boutique/produits/{$produit->id}/lien")
            ->assertNotFound();
    }
}
