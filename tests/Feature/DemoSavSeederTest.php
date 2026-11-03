<?php

namespace Tests\Feature;

use App\Models\DemandeSav;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSavSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/** Jeu de démo SAV (présentation Maintenancier) rejoué à chaque déploiement. */
class DemoSavSeederTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    public function test_sans_les_comptes_de_demo_le_seeder_ne_cree_rien(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, DemandeSav::count());
    }

    public function test_cree_les_demandes_pour_le_technicien_et_le_client_et_se_rejoue_sans_doublon(): void
    {
        $this->seed(DatabaseSeeder::class);
        $technicien = $this->creerTechnicien(['telephone' => '+2250779363809']);
        // Format sans le 0 initial (normaliser_telephone) : même compte reconnu.
        $client = $this->creerClient(['telephone' => '+225748968313']);
        $this->creerAchatLivre($client);

        $this->seed(DemoSavSeeder::class);
        $this->seed(DemoSavSeeder::class);

        $this->assertSame(9, DemandeSav::where('client_id', $client->id)->count());
        $this->assertSame(9, DemandeSav::whereNotNull('garantie_id')->count());

        $this->actingAs($technicien)->getJson('/api/v1/sav/demandes?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.total', 9);
    }
}
