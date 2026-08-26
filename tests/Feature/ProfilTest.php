<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class ProfilTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_un_client_peut_modifier_son_telephone_qui_est_normalise(): void
    {
        $client = $this->creerClient(['telephone' => '+225700000020']);

        $reponse = $this->actingAs($client)->patchJson('/api/v1/moi/profil', ['telephone' => '07 00 00 00 21']);

        $reponse->assertOk();
        $this->assertDatabaseHas('users', ['id' => $client->id, 'telephone' => '+225700000021']);
    }

    public function test_un_client_ne_peut_pas_prendre_le_telephone_d_un_autre_compte(): void
    {
        $this->creerClient(['telephone' => '+225700000022']);
        $client = $this->creerClient(['telephone' => '+225700000023']);

        $this->actingAs($client)->patchJson('/api/v1/moi/profil', ['telephone' => '0700000022'])->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => $client->id, 'telephone' => '+225700000023']);
    }

    public function test_un_client_sans_email_peut_modifier_son_nom_sans_en_fournir_un(): void
    {
        $client = $this->creerClient(['email' => null, 'telephone' => '+225700000024']);

        $this->actingAs($client)->patchJson('/api/v1/moi/profil', ['nom' => 'NouveauNom'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $client->id, 'nom' => 'NouveauNom', 'email' => null]);
    }
}
