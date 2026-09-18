<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * PATCH /moi/disponibilite — bascule "En ligne / Hors ligne" (écran Space,
 * app Livreur) sur Livreur::disponible, jusqu'ici modifiable seulement en
 * lecture par le coordinateur (LivreurController::show()).
 */
class LivreurDisponibiliteTest extends TestCase
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
        Livreur::create(['user_id' => $user->id, 'disponible' => false, 'type_vehicule' => 'moto']);

        return $user;
    }

    public function test_le_livreur_peut_se_mettre_en_ligne(): void
    {
        $livreur = $this->creerLivreur();

        $reponse = $this->actingAs($livreur)->patchJson('/api/v1/moi/disponibilite', ['disponible' => true]);

        $reponse->assertOk();
        $reponse->assertJsonPath('data.disponible', true);
        $this->assertTrue($livreur->livreur->fresh()->disponible);
    }

    public function test_le_profil_expose_la_disponibilite_pour_un_livreur(): void
    {
        $livreur = $this->creerLivreur();

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/moi/profil');

        $reponse->assertOk();
        $reponse->assertJsonPath('data.disponible', false);
        $reponse->assertJsonPath('data.type_vehicule', 'moto');
    }

    public function test_un_client_ne_peut_pas_basculer_sa_disponibilite(): void
    {
        $client = $this->creerClient();

        $this->actingAs($client)->patchJson('/api/v1/moi/disponibilite', ['disponible' => true])->assertForbidden();
    }
}
