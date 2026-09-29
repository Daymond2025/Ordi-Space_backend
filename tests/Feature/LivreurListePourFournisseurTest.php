<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * GET /fournisseur/moi/livreurs — onglet "Livreurs" (bottombar, app
 * Fournisseur), distinct de GET /coordinateur/livreurs (LivreurListeTest) :
 * même donnée (Livreur::disponible, zone_couverture) mais accessible au rôle
 * fournisseur, plus `photo`, sans `type_vehicule`.
 */
class LivreurListePourFournisseurTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerLivreur(array $attributsLivreur = [], array $attributsUser = []): User
    {
        $user = User::factory()->create(array_merge(['type_utilisateur' => ROLE_LIVREUR], $attributsUser));
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(array_merge(['user_id' => $user->id], $attributsLivreur));

        return $user;
    }

    public function test_le_fournisseur_voit_la_liste_avec_zone_et_disponibilite(): void
    {
        $fournisseur = $this->creerFournisseur();
        $livreur = $this->creerLivreur(
            ['zone_couverture' => 'Cocody', 'disponible' => true],
            ['telephone' => '+2250700000000']
        );

        $reponse = $this->actingAs($fournisseur)->getJson('/api/v1/fournisseur/moi/livreurs');

        $reponse->assertOk();
        $ligne = collect($reponse->json('data'))->firstWhere('user_id', $livreur->id);
        $this->assertNotNull($ligne);
        $this->assertSame('Cocody', $ligne['zone_couverture']);
        $this->assertTrue($ligne['disponible']);
        $this->assertSame('+2250700000000', $ligne['telephone']);
        $this->assertArrayHasKey('photo', $ligne);
        $this->assertArrayNotHasKey('type_vehicule', $ligne);
    }

    public function test_un_livreur_desactive_n_apparait_pas(): void
    {
        $fournisseur = $this->creerFournisseur();
        $livreur = $this->creerLivreur([], ['statut_compte' => STATUT_COMPTE_SUSPENDU]);

        $reponse = $this->actingAs($fournisseur)->getJson('/api/v1/fournisseur/moi/livreurs');

        $reponse->assertOk();
        $this->assertNull(collect($reponse->json('data'))->firstWhere('user_id', $livreur->id));
    }

    public function test_un_livreur_ne_peut_pas_consulter_cette_liste(): void
    {
        $autreLivreur = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $autreLivreur->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $autreLivreur->id]);

        $this->actingAs($autreLivreur)->getJson('/api/v1/fournisseur/moi/livreurs')->assertForbidden();
    }
}
