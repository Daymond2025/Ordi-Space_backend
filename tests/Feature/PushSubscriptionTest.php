<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Abonnements Web Push — voir App\Services\PushNotificationService pour
 * l'envoi (écran "Recherche d'un livreur", app Fournisseur).
 */
class PushSubscriptionTest extends TestCase
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
        \App\Models\Livreur::create(['user_id' => $user->id, 'disponible' => false, 'type_vehicule' => 'moto']);

        return $user;
    }

    public function test_la_cle_publique_vapid_est_exposee(): void
    {
        $livreur = $this->creerLivreur();

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/push/cle-publique');

        $reponse->assertOk();
        $this->assertNotEmpty($reponse->json('data.cle_publique'));
    }

    public function test_un_abonnement_peut_etre_enregistre_puis_supprime(): void
    {
        $livreur = $this->creerLivreur();

        $reponse = $this->actingAs($livreur)->postJson('/api/v1/moi/push-subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-1',
            'keys' => ['p256dh' => 'clé-p256dh-test', 'auth' => 'clé-auth-test'],
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $livreur->id,
            'endpoint_hash' => hash('sha256', 'https://fcm.googleapis.com/fcm/send/test-endpoint-1'),
        ]);

        $suppression = $this->actingAs($livreur)->deleteJson('/api/v1/moi/push-subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-1',
        ]);

        $suppression->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_enregistrer_deux_fois_le_meme_endpoint_met_a_jour_au_lieu_de_dupliquer(): void
    {
        $livreur = $this->creerLivreur();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-2';

        $this->actingAs($livreur)->postJson('/api/v1/moi/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'ancienne-cle', 'auth' => 'ancien-auth'],
        ])->assertCreated();

        $this->actingAs($livreur)->postJson('/api/v1/moi/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'nouvelle-cle', 'auth' => 'nouvel-auth'],
        ])->assertCreated();

        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertSame('nouvelle-cle', PushSubscription::first()->p256dh);
    }

    public function test_un_livreur_ne_supprime_pas_l_abonnement_d_un_autre(): void
    {
        $livreurA = $this->creerLivreur();
        $livreurB = $this->creerLivreur();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/partage';

        $this->actingAs($livreurA)->postJson('/api/v1/moi/push-subscriptions', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'clé-a', 'auth' => 'auth-a'],
        ])->assertCreated();

        // destroy() filtre par user_id ET endpoint_hash : B n'a jamais créé
        // cet abonnement (il appartient à A), sa suppression ne doit donc
        // rien toucher — même si la requête HTTP renvoie 200 dans tous les cas.
        $this->actingAs($livreurB)->deleteJson('/api/v1/moi/push-subscriptions', ['endpoint' => $endpoint])->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);
    }
}
