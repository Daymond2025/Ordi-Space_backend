<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class ExtractionClientTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_extrait_le_nom_et_le_telephone_depuis_le_texte_colle(): void
    {
        config(['services.anthropic.key' => 'fake-test-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => '{"nom": "Yao Kouassi", "telephone": "0700000099", "ville": "Abobo"}']],
            ], 200),
        ]);
        $coordinateur = $this->creerCoordinateur();

        $reponse = $this->actingAs($coordinateur)->postJson('/api/v1/clients/extraction', [
            'texte' => "Bonjour je m'appelle Yao Kouassi, mon numéro est 0700000099, je suis à Abobo, je veux le laptop.",
        ]);

        $reponse->assertOk();
        $this->assertSame('Yao Kouassi', $reponse->json('data.nom'));
        $this->assertSame('0700000099', $reponse->json('data.telephone'));
        $this->assertSame('Abobo', $reponse->json('data.ville'));
    }

    public function test_degrade_proprement_si_la_cle_api_est_absente(): void
    {
        config(['services.anthropic.key' => null]);
        $coordinateur = $this->creerCoordinateur();

        $reponse = $this->actingAs($coordinateur)->postJson('/api/v1/clients/extraction', [
            'texte' => 'Un texte quelconque',
        ]);

        $reponse->assertOk();
        $this->assertNull($reponse->json('data.nom'));
        $this->assertNull($reponse->json('data.telephone'));
        $this->assertNull($reponse->json('data.ville'));
    }

    public function test_degrade_proprement_si_l_appel_a_claude_echoue(): void
    {
        config(['services.anthropic.key' => 'fake-test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);
        $coordinateur = $this->creerCoordinateur();

        $reponse = $this->actingAs($coordinateur)->postJson('/api/v1/clients/extraction', [
            'texte' => 'Un texte quelconque',
        ]);

        $reponse->assertOk();
        $this->assertNull($reponse->json('data.nom'));
        $this->assertNull($reponse->json('data.telephone'));
        $this->assertNull($reponse->json('data.ville'));
    }

    public function test_un_role_sans_permission_ne_peut_pas_extraire(): void
    {
        $client = $this->creerClient();

        $this->actingAs($client)->postJson('/api/v1/clients/extraction', [
            'texte' => 'Un texte quelconque',
        ])->assertForbidden();
    }
}
