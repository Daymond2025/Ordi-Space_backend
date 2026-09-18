<?php

namespace Tests\Feature;

use App\Models\Adresse;
use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\LigneCommande;
use App\Models\Livraison;
use App\Models\Livreur;
use App\Models\Localite;
use App\Models\Paiement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Encaissement Mobile Money via Wave — POST /commandes/{id}/paiement/wave
 * (crée une session Wave, Paiement en_attente) et le webhook qui seul peut
 * le confirmer (WaveWebhookController). Aucun appel réseau réel : Http::fake().
 */
class WavePaiementTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config([
            'services.wave.api_key' => 'wave_ci_test_fake',
            'services.wave.base_url' => 'https://api.wave.example/v1',
            'services.wave.min_amount' => 100,
            'services.wave.max_amount' => 500000,
            'services.wave.webhook_secret' => 'test-webhook-secret',
            'services.wave.checkout_success_url' => 'https://ordisapce.daymondboutique.com',
            'services.wave.checkout_error_url' => 'https://ordisapce.daymondboutique.com',
        ]);
    }

    private function creerCommandeAvecLivreur(): array
    {
        $livreurUser = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $livreurUser->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $livreurUser->id]);

        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();
        $commercial = $this->creerAgentIa();
        $canal = CanalVente::firstOrCreate(['nom_canal' => 'Boutique en ligne']);
        $localite = Localite::firstOrCreate(['nom' => 'Cocody'], ['type' => 'commune_abidjan']);

        $commande = Commande::create([
            'client_id' => $client->id,
            'commercial_id' => $commercial->id,
            'canal_vente_id' => $canal->id,
            'statut_commande' => STATUT_COMMANDE_EN_LIVRAISON,
            'montant_total' => $produit->prix,
            'date_commande' => now(),
        ]);

        LigneCommande::create([
            'commande_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 1,
            'prix_unitaire' => $produit->prix,
        ]);

        $adresse = Adresse::create([
            'client_id' => $client->id,
            'rue' => 'Rue Test',
            'ville' => 'Abidjan',
            'pays' => "Côte d'Ivoire",
            'localite_id' => $localite->id,
        ]);

        Livraison::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreurUser->id,
            'adresse_id' => $adresse->id,
            'statut_livraison' => STATUT_LIVRAISON_EN_COURS,
        ]);

        return [$commande->fresh('livraison'), $livreurUser];
    }

    private function signatureWebhook(string $corps, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$corps}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    public function test_le_livreur_initie_un_paiement_wave(): void
    {
        Http::fake([
            'api.wave.example/*' => Http::response([
                'id' => 'cos-test123',
                'wave_launch_url' => 'https://pay.wave.com/c/cos-test123',
                'checkout_status' => 'open',
            ], 200),
        ]);

        [$commande, $livreur] = $this->creerCommandeAvecLivreur();

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/commandes/{$commande->id}/paiement/wave");

        $reponse->assertCreated();
        $reponse->assertJsonPath('data.wave_launch_url', 'https://pay.wave.com/c/cos-test123');

        $paiement = Paiement::where('commande_id', $commande->id)->firstOrFail();
        $this->assertSame(STATUT_PAIEMENT_EN_ATTENTE, $paiement->statut_paiement);
        $this->assertSame('cos-test123', $paiement->wave_checkout_session_id);
        $this->assertSame(MODE_PAIEMENT_MOBILE_MONEY, $paiement->mode_paiement);
    }

    public function test_le_lien_wave_est_conserve_pour_reafficher_le_qr_plus_tard(): void
    {
        Http::fake([
            'api.wave.example/*' => Http::response(['id' => 'cos-resume', 'wave_launch_url' => 'https://pay.wave.com/c/cos-resume'], 200),
        ]);

        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$commande->id}/paiement/wave")->assertCreated();

        // Simule le livreur qui quitte l'écran puis y revient : seul un GET
        // (pas la réponse du POST initial) doit permettre de retrouver le QR.
        $reponse = $this->actingAs($livreur)->getJson("/api/v1/commandes/{$commande->id}/paiement");

        $reponse->assertOk();
        $reponse->assertJsonPath('data.wave_launch_url', 'https://pay.wave.com/c/cos-resume');
    }

    /**
     * Régression sécurité : PERMISSION_COMMANDES_CONSULTER (qui garde cette
     * route) est large — client, commercial et livreur l'ont tous, pas
     * seulement pour leurs propres commandes. Sans vérification par
     * enregistrement, n'importe quel livreur pouvait lire le paiement
     * (montant, wave_launch_url...) d'une commande qui n'est pas la sienne.
     */
    public function test_un_livreur_etranger_a_la_commande_ne_peut_pas_voir_son_paiement(): void
    {
        Http::fake([
            'api.wave.example/*' => Http::response(['id' => 'cos-etranger', 'wave_launch_url' => 'https://pay.wave.com/c/cos-etranger'], 200),
        ]);

        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$commande->id}/paiement/wave")->assertCreated();

        $autreLivreur = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $autreLivreur->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $autreLivreur->id]);

        $this->actingAs($autreLivreur)->getJson("/api/v1/commandes/{$commande->id}/paiement")->assertForbidden();
    }

    public function test_un_autre_livreur_ne_peut_pas_initier_le_paiement(): void
    {
        Http::fake(['api.wave.example/*' => Http::response(['id' => 'cos-x', 'wave_launch_url' => 'https://pay.wave.com/c/x'])]);

        [$commande] = $this->creerCommandeAvecLivreur();
        $autreLivreur = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $autreLivreur->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $autreLivreur->id]);

        $this->actingAs($autreLivreur)->postJson("/api/v1/commandes/{$commande->id}/paiement/wave")->assertForbidden();
    }

    public function test_le_livreur_confirme_manuellement_un_paiement_wave_en_attente(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreur->id,
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY,
            'montant' => $commande->montantNet(),
            'statut_paiement' => STATUT_PAIEMENT_EN_ATTENTE,
            'wave_checkout_session_id' => 'cos-manuel-1',
        ]);

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/paiements/{$paiement->id}/confirmer-manuellement");

        $reponse->assertOk();
        $paiement->refresh();
        $this->assertSame(STATUT_PAIEMENT_CONFIRME, $paiement->statut_paiement);
        $this->assertNotNull($paiement->date_paiement);
        // La confirmation (webhook ou secours manuel) marque aussi la
        // mission comme livrée — voir Livraison::marquerLivree().
        $this->assertSame(STATUT_LIVRAISON_LIVREE, $commande->fresh('livraison')->livraison->statut_livraison);
    }

    public function test_un_autre_livreur_ne_peut_pas_confirmer_manuellement(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreur->id,
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY,
            'montant' => $commande->montantNet(),
            'statut_paiement' => STATUT_PAIEMENT_EN_ATTENTE,
            'wave_checkout_session_id' => 'cos-manuel-2',
        ]);
        $autreLivreur = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $autreLivreur->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $autreLivreur->id]);

        $this->actingAs($autreLivreur)->postJson("/api/v1/paiements/{$paiement->id}/confirmer-manuellement")->assertForbidden();
    }

    public function test_on_ne_peut_pas_confirmer_manuellement_un_paiement_deja_confirme(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreur->id,
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY,
            'montant' => $commande->montantNet(),
            'statut_paiement' => STATUT_PAIEMENT_CONFIRME,
            'date_paiement' => now(),
            'wave_checkout_session_id' => 'cos-manuel-3',
        ]);

        $this->actingAs($livreur)->postJson("/api/v1/paiements/{$paiement->id}/confirmer-manuellement")->assertStatus(422);
    }

    public function test_le_webhook_confirme_le_paiement_avec_une_signature_valide(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreur->id,
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY,
            'montant' => $commande->montantNet(),
            'statut_paiement' => STATUT_PAIEMENT_EN_ATTENTE,
            'wave_checkout_session_id' => 'cos-webhook-1',
        ]);

        $corps = json_encode(['id' => 'evt-1', 'type' => 'checkout.session.completed', 'data' => ['id' => 'cos-webhook-1']]);
        $signature = $this->signatureWebhook($corps, 'test-webhook-secret');

        $reponse = $this->call('POST', '/api/v1/webhooks/wave', [], [], [], [
            'HTTP_Wave-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $corps);

        $reponse->assertOk();
        $this->assertSame(STATUT_PAIEMENT_CONFIRME, $paiement->fresh()->statut_paiement);
        $this->assertNotNull($paiement->fresh()->date_paiement);
        $this->assertSame(STATUT_LIVRAISON_LIVREE, $commande->fresh('livraison')->livraison->statut_livraison);
    }

    public function test_le_webhook_rejoue_ne_regenere_pas_deux_fois_les_effets_de_bord(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreur->id,
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY,
            'montant' => $commande->montantNet(),
            'statut_paiement' => STATUT_PAIEMENT_EN_ATTENTE,
            'wave_checkout_session_id' => 'cos-webhook-replay',
        ]);

        $corps = json_encode(['id' => 'evt-replay', 'type' => 'checkout.session.completed', 'data' => ['id' => 'cos-webhook-replay']]);
        $signature = $this->signatureWebhook($corps, 'test-webhook-secret');
        $headers = ['HTTP_Wave-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'];

        $this->call('POST', '/api/v1/webhooks/wave', [], [], [], $headers, $corps)->assertOk();
        $dateLivraisonPremierAppel = $commande->fresh('livraison')->livraison->date_livraison_effective;

        // Un webhook Wave rejoué (retry réseau) ne doit pas régénérer
        // garantie/crédits une seconde fois — Livraison::marquerLivree()
        // est un no-op une fois déjà "livree".
        $this->call('POST', '/api/v1/webhooks/wave', [], [], [], $headers, $corps)->assertOk();

        $this->assertEquals($dateLivraisonPremierAppel, $commande->fresh('livraison')->livraison->date_livraison_effective);
    }

    public function test_le_webhook_passe_le_paiement_en_echec(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreur->id,
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY,
            'montant' => $commande->montantNet(),
            'statut_paiement' => STATUT_PAIEMENT_EN_ATTENTE,
            'wave_checkout_session_id' => 'cos-webhook-2',
        ]);

        $corps = json_encode(['id' => 'evt-2', 'type' => 'checkout.session.payment_failed', 'data' => ['id' => 'cos-webhook-2']]);
        $signature = $this->signatureWebhook($corps, 'test-webhook-secret');

        $this->call('POST', '/api/v1/webhooks/wave', [], [], [], [
            'HTTP_Wave-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $corps)->assertOk();

        $this->assertSame(STATUT_PAIEMENT_ECHOUE, $paiement->fresh()->statut_paiement);
    }

    public function test_le_webhook_rejette_une_signature_invalide(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreur->id,
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY,
            'montant' => $commande->montantNet(),
            'statut_paiement' => STATUT_PAIEMENT_EN_ATTENTE,
            'wave_checkout_session_id' => 'cos-webhook-3',
        ]);

        $corps = json_encode(['id' => 'evt-3', 'type' => 'checkout.session.completed', 'data' => ['id' => 'cos-webhook-3']]);

        $reponse = $this->call('POST', '/api/v1/webhooks/wave', [], [], [], [
            'HTTP_Wave-Signature' => 't='.time().',v1=signature-invalide',
            'CONTENT_TYPE' => 'application/json',
        ], $corps);

        $reponse->assertUnauthorized();
        $this->assertSame(STATUT_PAIEMENT_EN_ATTENTE, $paiement->fresh()->statut_paiement);
    }

    public function test_le_webhook_rejette_un_timestamp_trop_ancien(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreur->id,
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY,
            'montant' => $commande->montantNet(),
            'statut_paiement' => STATUT_PAIEMENT_EN_ATTENTE,
            'wave_checkout_session_id' => 'cos-webhook-4',
        ]);

        $corps = json_encode(['id' => 'evt-4', 'type' => 'checkout.session.completed', 'data' => ['id' => 'cos-webhook-4']]);
        $signature = $this->signatureWebhook($corps, 'test-webhook-secret', time() - 600);

        $reponse = $this->call('POST', '/api/v1/webhooks/wave', [], [], [], [
            'HTTP_Wave-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $corps);

        $reponse->assertUnauthorized();
        $this->assertSame(STATUT_PAIEMENT_EN_ATTENTE, $paiement->fresh()->statut_paiement);
    }
}
