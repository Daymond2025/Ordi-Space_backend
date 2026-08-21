<?php

namespace Tests\Feature;

use App\Models\MessageAssistantIa;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class AssistantIaTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_le_message_du_client_est_conserve_meme_si_la_cle_api_est_absente(): void
    {
        config(['services.anthropic.key' => null]);
        $client = $this->creerClient();

        $reponse = $this->actingAs($client)->postJson('/api/v1/assistant/messages', [
            'message' => 'Où en est ma commande ?',
        ]);

        $reponse->assertServiceUnavailable();
        $this->assertDatabaseHas('messages_assistant_ia', [
            'client_id' => $client->id, 'role' => ROLE_MESSAGE_IA_CLIENT, 'contenu' => 'Où en est ma commande ?',
        ]);
    }

    public function test_le_client_obtient_une_reponse_de_l_agent_quand_claude_repond(): void
    {
        config(['services.anthropic.key' => 'fake-test-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'Bonjour, comment puis-je vous aider ?']],
            ], 200),
        ]);
        $client = $this->creerClient();

        $reponse = $this->actingAs($client)->postJson('/api/v1/assistant/messages', ['message' => 'Bonjour']);

        $reponse->assertCreated();
        $this->assertSame('Bonjour, comment puis-je vous aider ?', $reponse->json('data.message_assistant.contenu'));
        $this->assertDatabaseCount('messages_assistant_ia', 2);
    }

    public function test_un_client_ne_voit_pas_l_historique_d_un_autre_client(): void
    {
        $clientA = $this->creerClient();
        $clientB = $this->creerClient();

        MessageAssistantIa::create([
            'client_id' => $clientA->id, 'role' => ROLE_MESSAGE_IA_CLIENT,
            'contenu' => 'Message privé A', 'date_envoi' => now(),
        ]);

        $reponse = $this->actingAs($clientB)->getJson('/api/v1/assistant/messages');
        $this->assertCount(0, $reponse->json('data'));
    }

    public function test_un_admin_peut_consulter_les_conversations_de_tous_les_clients(): void
    {
        $client = $this->creerClient();
        $admin = $this->creerAdmin();

        MessageAssistantIa::create([
            'client_id' => $client->id, 'role' => ROLE_MESSAGE_IA_CLIENT,
            'contenu' => 'Bonjour Ellah', 'date_envoi' => now(),
        ]);

        $liste = $this->actingAs($admin)->getJson('/api/v1/admin/assistant-ia/clients');
        $liste->assertOk();
        $this->assertCount(1, $liste->json('data.data'));

        $detail = $this->actingAs($admin)->getJson("/api/v1/admin/assistant-ia/clients/{$client->id}/messages");
        $detail->assertOk();
        $this->assertCount(1, $detail->json('data.messages'));
    }

    public function test_un_client_ne_peut_pas_consulter_la_liste_admin_des_conversations(): void
    {
        $client = $this->creerClient();

        $this->actingAs($client)->getJson('/api/v1/admin/assistant-ia/clients')->assertForbidden();
    }
}
