<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * GET /coordinateur/livreurs — écran "Livreurs" (Espace Coordinateur),
 * distinct de GET /livreurs (LivreurController::index()) qui alimente le
 * sélecteur "Envoyer à un livreur" avec une forme plus simple.
 */
class LivreurListeTest extends TestCase
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

    public function test_la_liste_expose_vehicule_zone_et_disponibilite(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur(
            ['type_vehicule' => 'moto', 'zone_couverture' => 'Cocody', 'disponible' => false],
            ['telephone' => '+2250700000000']
        );

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/livreurs');

        $reponse->assertOk();
        $ligne = collect($reponse->json('data'))->firstWhere('user_id', $livreur->id);
        $this->assertNotNull($ligne);
        $this->assertSame('moto', $ligne['type_vehicule']);
        $this->assertSame('Cocody', $ligne['zone_couverture']);
        $this->assertFalse($ligne['disponible']);
        $this->assertSame('+2250700000000', $ligne['telephone']);
    }

    public function test_disponible_vaut_true_par_defaut(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur();

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/livreurs');

        $ligne = collect($reponse->json('data'))->firstWhere('user_id', $livreur->id);
        $this->assertTrue($ligne['disponible']);
    }

    /**
     * GET /livreurs (index(), sélecteur "Envoyer à un livreur") garde sa
     * forme d'origine — aucune régression apportée par liste().
     */
    public function test_le_selecteur_envoyer_a_un_livreur_est_inchange(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $this->creerLivreur();

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/livreurs');

        $reponse->assertOk();
        $ligne = $reponse->json('data')[0];
        $this->assertArrayHasKey('id', $ligne);
        $this->assertArrayNotHasKey('user_id', $ligne);
        $this->assertArrayNotHasKey('disponible', $ligne);
    }

    public function test_un_commercial_ne_peut_pas_consulter_la_liste(): void
    {
        $commercial = $this->creerCommercial();

        $this->actingAs($commercial)->getJson('/api/v1/coordinateur/livreurs')->assertForbidden();
    }
}
