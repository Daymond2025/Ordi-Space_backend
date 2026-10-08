<?php

namespace Tests\Feature;

use App\Models\Commercial;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * "Mon Profil" (app Commercial) — GET /moi/profil doit exposer
 * nom_entreprise/localisation (saisis par le coordinateur, voir migration
 * add_nom_entreprise_et_localisation_to_commerciaux_table), même convention
 * que Fournisseur::nom_entreprise exposé pour ROLE_FOURNISSEUR.
 */
class CommercialProfilTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_le_profil_expose_lentreprise_et_la_localisation_du_commercial(): void
    {
        $commercial = $this->creerCommercial();
        Commercial::where('user_id', $commercial->id)->update(['nom_entreprise' => 'Ebe com', 'localisation' => 'Gagnoa, au commerce']);

        $reponse = $this->actingAs($commercial)->getJson('/api/v1/moi/profil');

        $reponse->assertOk();
        $reponse->assertJsonPath('data.nom_entreprise', 'Ebe com');
        $reponse->assertJsonPath('data.localisation', 'Gagnoa, au commerce');
    }

    public function test_le_profil_dun_client_nexpose_pas_ces_champs(): void
    {
        $client = $this->creerClient();

        $reponse = $this->actingAs($client)->getJson('/api/v1/moi/profil');

        $reponse->assertOk();
        $this->assertArrayNotHasKey('nom_entreprise', $reponse->json('data'));
    }
}
