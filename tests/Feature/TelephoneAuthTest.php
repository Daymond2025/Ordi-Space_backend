<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class TelephoneAuthTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_un_numero_inconnu_ne_cree_aucun_compte_et_signale_l_inscription(): void
    {
        $reponse = $this->postJson('/api/v1/auth/telephone/otp', ['telephone' => '0700000001']);

        $reponse->assertOk();
        $this->assertFalse($reponse->json('data.compte_existant'));
        $this->assertDatabaseMissing('users', ['telephone' => '+225700000001']);
    }

    public function test_l_inscription_par_telephone_cree_le_compte_et_emet_un_otp(): void
    {
        $reponse = $this->postJson('/api/v1/auth/telephone/inscription', [
            'telephone' => '07 00 00 00 02',
            'nom' => 'Kouassi',
            'prenom' => 'Awa',
        ]);

        $reponse->assertCreated();
        $reponse->assertJsonPath('data.code_debug', fn ($code) => is_string($code) && strlen($code) === OTP_LONGUEUR);

        $this->assertDatabaseHas('users', [
            'telephone' => '+225700000002', 'nom' => 'Kouassi', 'type_utilisateur' => ROLE_CLIENT, 'email' => null,
        ]);
        $this->assertDatabaseHas('clients', ['user_id' => $reponse->json('data.user_id')]);
    }

    public function test_le_flux_complet_inscription_puis_verification_otp_connecte_le_client(): void
    {
        $inscription = $this->postJson('/api/v1/auth/telephone/inscription', [
            'telephone' => '0700000003', 'nom' => 'Yao',
        ]);
        $inscription->assertCreated();

        $verification = $this->postJson('/api/v1/auth/verify-otp', [
            'user_id' => $inscription->json('data.user_id'),
            'code' => $inscription->json('data.code_debug'),
            'device_name' => 'test-device',
        ]);

        $verification->assertOk();
        $this->assertNotEmpty($verification->json('data.token'));
        $this->assertSame(ROLE_CLIENT, $verification->json('data.user.type_utilisateur'));
    }

    public function test_demander_otp_reconnait_un_numero_deja_inscrit(): void
    {
        $client = $this->creerClient(['telephone' => '+225700000004']);

        $reponse = $this->postJson('/api/v1/auth/telephone/otp', ['telephone' => '0700000004']);

        $reponse->assertOk();
        $this->assertTrue($reponse->json('data.compte_existant'));
        $this->assertSame($client->id, $reponse->json('data.user_id'));
    }

    public function test_on_ne_peut_pas_s_inscrire_deux_fois_avec_le_meme_numero(): void
    {
        $this->creerClient(['telephone' => '+225700000005']);

        $this->postJson('/api/v1/auth/telephone/inscription', [
            'telephone' => '0700000005', 'nom' => 'Doublon',
        ])->assertUnprocessable();
    }

    public function test_un_compte_client_suspendu_est_rejete_a_la_demande_d_otp(): void
    {
        $this->creerClient(['telephone' => '+225700000006', 'statut_compte' => STATUT_COMPTE_SUSPENDU]);

        $this->postJson('/api/v1/auth/telephone/otp', ['telephone' => '0700000006'])->assertUnprocessable();
    }

    public function test_un_client_ne_peut_plus_se_connecter_par_email_et_mot_de_passe(): void
    {
        $client = $this->creerClient(['email' => 'ancien-client@example.com', 'password' => bcrypt('MotDePasse123')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ancien-client@example.com',
            'password' => 'MotDePasse123',
            'device_name' => 'test-device',
        ])->assertUnprocessable();

        $this->assertNotNull($client);
    }

    public function test_un_commercial_peut_enregistrer_un_nouveau_client_par_telephone_pour_une_vente(): void
    {
        $commercial = $this->creerCommercial();

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/clients/creation-rapide', [
            'nom' => 'Traore', 'prenom' => 'Ibrahim', 'telephone' => '0700000007',
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('users', [
            'telephone' => '+225700000007', 'nom' => 'Traore', 'type_utilisateur' => ROLE_CLIENT,
        ]);

        // Le client_id obtenu est immédiatement utilisable par le flux de
        // commande existant, inchangé (CommandeController::store).
        $produit = $this->creerProduitPhysique();
        $clientId = $reponse->json('data.id');

        $commande = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $clientId,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'adresse_id' => $this->creerAdresseAvecLocalite(User::findOrFail($clientId))->id,
        ]);

        $commande->assertCreated();
    }

    public function test_appeler_deux_fois_la_creation_rapide_pour_le_meme_numero_est_idempotent(): void
    {
        $commercial = $this->creerCommercial();
        $body = ['nom' => 'Kone', 'telephone' => '0700000008'];

        $premier = $this->actingAs($commercial)->postJson('/api/v1/clients/creation-rapide', $body);
        $second = $this->actingAs($commercial)->postJson('/api/v1/clients/creation-rapide', $body);

        $premier->assertCreated();
        $second->assertOk();
        $this->assertSame($premier->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, User::where('telephone', '+225700000008')->count());
    }

    public function test_un_admin_peut_aussi_enregistrer_un_nouveau_client_par_telephone(): void
    {
        $admin = $this->creerAdmin();

        $reponse = $this->actingAs($admin)->postJson('/api/v1/clients/creation-rapide', [
            'nom' => 'Kouame', 'prenom' => 'Aya', 'telephone' => '0700000011',
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('users', [
            'telephone' => '+225700000011', 'nom' => 'Kouame', 'type_utilisateur' => ROLE_CLIENT,
        ]);
    }

    public function test_un_client_ne_peut_pas_creer_un_autre_client_rapidement(): void
    {
        $client = $this->creerClient();

        $this->actingAs($client)->postJson('/api/v1/clients/creation-rapide', [
            'nom' => 'Interdit', 'telephone' => '0700000009',
        ])->assertForbidden();
    }

    public function test_la_creation_rapide_refuse_un_numero_deja_utilise_par_un_non_client(): void
    {
        $commercial = $this->creerCommercial();
        $admin = $this->creerAdmin(['telephone' => '+225700000010']);

        $this->actingAs($commercial)->postJson('/api/v1/clients/creation-rapide', [
            'nom' => 'Conflit', 'telephone' => '0700000010',
        ])->assertUnprocessable();

        $this->assertNotNull($admin);
    }

    public function test_un_code_errone_incremente_le_compteur_de_tentatives(): void
    {
        $inscription = $this->postJson('/api/v1/auth/telephone/inscription', [
            'telephone' => '0700000012', 'nom' => 'Diallo',
        ]);

        $this->postJson('/api/v1/auth/verify-otp', [
            'user_id' => $inscription->json('data.user_id'), 'code' => '000000', 'device_name' => 'test-device',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('users', [
            'id' => $inscription->json('data.user_id'), 'two_factor_tentatives' => 1,
        ]);
    }

    public function test_le_code_est_invalide_apres_trop_de_tentatives_echouees(): void
    {
        $inscription = $this->postJson('/api/v1/auth/telephone/inscription', [
            'telephone' => '0700000013', 'nom' => 'Bakayoko',
        ]);
        $userId = $inscription->json('data.user_id');
        $bonCode = $inscription->json('data.code_debug');

        // Simule OTP_TENTATIVES_MAX échecs déjà comptabilisés (sans refaire
        // les appels HTTP un par un, pour ne pas dépendre du throttle réseau
        // de la route, qui est un mécanisme distinct testé ailleurs).
        User::where('id', $userId)->update(['two_factor_tentatives' => OTP_TENTATIVES_MAX]);

        // Même avec le BON code, la tentative est refusée : le code a été
        // invalidé après le seuil, il faut en redemander un.
        $this->postJson('/api/v1/auth/verify-otp', [
            'user_id' => $userId, 'code' => $bonCode, 'device_name' => 'test-device',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => $userId, 'two_factor_code' => null]);
    }
}
