<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class ReclamationTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_un_client_peut_deposer_une_reclamation(): void
    {
        $client = $this->creerClient();

        $reponse = $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Livraison en retard',
            'description' => 'Ma commande devait arriver hier.',
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('reclamations', ['client_id' => $client->id, 'statut' => STATUT_RECLAMATION_NOUVELLE]);
    }

    public function test_un_client_ne_voit_que_ses_propres_reclamations(): void
    {
        $clientA = $this->creerClient();
        $clientB = $this->creerClient();

        $this->actingAs($clientA)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet A', 'description' => 'Description A',
        ])->assertCreated();
        $this->actingAs($clientB)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet B', 'description' => 'Description B',
        ])->assertCreated();

        $reponse = $this->actingAs($clientA)->getJson('/api/v1/reclamations');
        $sujets = collect($reponse->json('data.data') ?? $reponse->json('data'))->pluck('sujet');

        $this->assertContains('Sujet A', $sujets);
        $this->assertNotContains('Sujet B', $sujets);
    }

    public function test_un_admin_peut_repondre_a_une_reclamation_et_le_client_voit_la_reponse(): void
    {
        $client = $this->creerClient();
        $admin = $this->creerAdmin();

        $creation = $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Produit défectueux', 'description' => 'Écran cassé à la livraison.',
        ]);
        $id = $creation->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/reclamations/{$id}/repondre", [
            'statut' => STATUT_RECLAMATION_RESOLUE,
            'reponse_admin' => 'Un nouvel écran vous a été envoyé.',
        ])->assertOk();

        $vueClient = $this->actingAs($client)->getJson("/api/v1/reclamations/{$id}");
        $vueClient->assertOk();
        $this->assertSame('resolue', $vueClient->json('data.statut'));
        $this->assertSame('Un nouvel écran vous a été envoyé.', $vueClient->json('data.reponse_admin'));
    }

    public function test_un_client_ne_peut_pas_repondre_a_une_reclamation(): void
    {
        $client = $this->creerClient();

        $creation = $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet', 'description' => 'Description',
        ]);
        $id = $creation->json('data.id');

        $this->actingAs($client)->patchJson("/api/v1/reclamations/{$id}/repondre", [
            'statut' => STATUT_RECLAMATION_RESOLUE,
            'reponse_admin' => 'Je me réponds moi-même',
        ])->assertForbidden();
    }
}
