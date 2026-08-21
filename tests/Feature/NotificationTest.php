<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_un_admin_peut_notifier_un_client_et_le_client_la_voit(): void
    {
        $client = $this->creerClient();
        $admin = $this->creerAdmin();

        $this->actingAs($admin)->postJson("/api/v1/admin/clients/{$client->id}/notifier", [
            'contenu' => 'Votre commande a été validée.',
        ])->assertCreated();

        $reponse = $this->actingAs($client)->getJson('/api/v1/moi/notifications');
        $reponse->assertOk();
        $this->assertSame('Votre commande a été validée.', $reponse->json('data.data.0.contenu'));
        $this->assertFalse($reponse->json('data.data.0.lu'));
    }

    public function test_un_client_ne_voit_pas_les_notifications_d_un_autre_client(): void
    {
        $clientA = $this->creerClient();
        $clientB = $this->creerClient();
        $admin = $this->creerAdmin();

        $this->actingAs($admin)->postJson("/api/v1/admin/clients/{$clientA->id}/notifier", [
            'contenu' => 'Message pour A',
        ])->assertCreated();

        $reponse = $this->actingAs($clientB)->getJson('/api/v1/moi/notifications');
        $this->assertCount(0, $reponse->json('data.data'));
    }

    public function test_un_client_peut_marquer_sa_notification_comme_lue(): void
    {
        $client = $this->creerClient();
        $admin = $this->creerAdmin();

        $creation = $this->actingAs($admin)->postJson("/api/v1/admin/clients/{$client->id}/notifier", [
            'contenu' => 'Test',
        ]);
        $id = $creation->json('data.id');

        $this->actingAs($client)->patchJson("/api/v1/moi/notifications/{$id}/lue")->assertOk();
        $this->assertDatabaseHas('notifications_ordispace', ['id' => $id, 'lu' => true]);
    }

    public function test_un_client_ne_peut_pas_marquer_comme_lue_la_notification_d_un_autre(): void
    {
        $clientA = $this->creerClient();
        $clientB = $this->creerClient();
        $admin = $this->creerAdmin();

        $creation = $this->actingAs($admin)->postJson("/api/v1/admin/clients/{$clientA->id}/notifier", [
            'contenu' => 'Pour A seulement',
        ]);
        $id = $creation->json('data.id');

        $this->actingAs($clientB)->patchJson("/api/v1/moi/notifications/{$id}/lue")->assertForbidden();
    }
}
