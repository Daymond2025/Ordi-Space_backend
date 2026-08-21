<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\TransactionPortefeuille;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class PortefeuilleTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_un_client_voit_son_solde_et_son_historique(): void
    {
        $client = $this->creerClient();
        Client::where('user_id', $client->id)->update(['solde_portefeuille' => 5000]);

        TransactionPortefeuille::create([
            'client_id' => $client->id, 'type' => TYPE_TRANSACTION_PORTEFEUILLE_CREDIT,
            'montant' => 5000, 'motif' => 'Parrainage — commande #1', 'solde_apres' => 5000,
            'date_transaction' => now(),
        ]);

        $reponse = $this->actingAs($client)->getJson('/api/v1/moi/portefeuille');
        $reponse->assertOk();
        $this->assertEquals(5000, $reponse->json('data.solde'));
        $this->assertCount(1, $reponse->json('data.transactions.data'));
    }

    public function test_le_solde_d_un_client_n_apparait_pas_pour_un_autre(): void
    {
        $clientA = $this->creerClient();
        $clientB = $this->creerClient();
        Client::where('user_id', $clientA->id)->update(['solde_portefeuille' => 5000]);

        $reponse = $this->actingAs($clientB)->getJson('/api/v1/moi/portefeuille');
        $this->assertEquals(0, $reponse->json('data.solde'));
        $this->assertCount(0, $reponse->json('data.transactions.data'));
    }
}
