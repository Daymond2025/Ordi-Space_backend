<?php

namespace Tests\Feature;

use App\Models\Privilege;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class PrivilegeTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_un_client_ne_voit_que_les_privileges_actifs_et_en_periode_de_validite(): void
    {
        $client = $this->creerClient();

        Privilege::create([
            'titre' => 'Actif', 'type_privilege' => 'remise_pourcentage', 'valeur' => 10,
            'code_promo' => 'ACTIF10', 'actif' => true,
        ]);
        Privilege::create([
            'titre' => 'Désactivé', 'type_privilege' => 'remise_pourcentage', 'valeur' => 20,
            'code_promo' => 'INACTIF20', 'actif' => false,
        ]);
        Privilege::create([
            'titre' => 'Expiré', 'type_privilege' => 'remise_pourcentage', 'valeur' => 30,
            'code_promo' => 'EXPIRE30', 'actif' => true, 'date_fin' => now()->subDay(),
        ]);

        $reponse = $this->actingAs($client)->getJson('/api/v1/privileges');
        $titres = collect($reponse->json('data'))->pluck('titre');

        $this->assertContains('Actif', $titres);
        $this->assertNotContains('Désactivé', $titres);
        $this->assertNotContains('Expiré', $titres);
    }

    public function test_un_admin_voit_aussi_les_privileges_desactives(): void
    {
        $admin = $this->creerAdmin();

        Privilege::create([
            'titre' => 'Désactivé', 'type_privilege' => 'remise_pourcentage', 'valeur' => 20,
            'code_promo' => 'INACTIF20', 'actif' => false,
        ]);

        $reponse = $this->actingAs($admin)->getJson('/api/v1/privileges');
        $this->assertCount(1, $reponse->json('data'));
    }

    public function test_un_client_sans_permission_ne_peut_pas_creer_de_privilege(): void
    {
        $client = $this->creerClient();

        $this->actingAs($client)->postJson('/api/v1/privileges', [
            'titre' => 'Triché', 'type_privilege' => 'remise_pourcentage', 'valeur' => 99, 'code_promo' => 'HACK99',
        ])->assertForbidden();
    }
}
