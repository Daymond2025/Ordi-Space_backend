<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Mot de passe oublié (personnel e-mail/mot de passe) : POST /auth/mot-de-passe/oublie
 * envoie un code par e-mail, POST /auth/mot-de-passe/reinitialiser le consomme.
 * Même mécanique que la 2FA (AuthController::verifyOtp()), colonnes dédiées.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
    }

    private function creerLivreur(array $override = []): User
    {
        $user = User::factory()->create(array_merge([
            'type_utilisateur' => ROLE_LIVREUR,
            'email' => 'jean.koffi@example.com',
            'password' => Hash::make('AncienMdp1'),
            'statut_compte' => STATUT_COMPTE_ACTIF,
        ], $override));
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id]);

        return $user;
    }

    private function codeEnvoye(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, PasswordResetCodeNotification::class, function ($notification) use (&$code) {
            $code = (fn () => $this->code)->call($notification);

            return true;
        });

        return $code;
    }

    public function test_demander_un_code_envoie_un_e_mail_et_le_code_fonctionne(): void
    {
        $user = $this->creerLivreur();

        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => $user->email])->assertOk();

        $code = $this->codeEnvoye($user);
        $this->assertNotNull($user->fresh()->password_reset_code);

        $reponse = $this->postJson('/api/v1/auth/mot-de-passe/reinitialiser', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'NouveauMdp1',
            'password_confirmation' => 'NouveauMdp1',
        ]);

        $reponse->assertOk();
        $user->refresh();
        $this->assertTrue(Hash::check('NouveauMdp1', $user->password));
        $this->assertNull($user->password_reset_code);
        $this->assertSame(0, $user->password_reset_tentatives);
    }

    public function test_le_nouveau_mot_de_passe_fonctionne_a_la_connexion_et_revoque_les_anciennes_sessions(): void
    {
        $user = $this->creerLivreur();
        $ancienJeton = $user->createToken('ancien-appareil')->plainTextToken;

        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => $user->email])->assertOk();
        $code = $this->codeEnvoye($user);
        $this->postJson('/api/v1/auth/mot-de-passe/reinitialiser', [
            'email' => $user->email, 'code' => $code, 'password' => 'NouveauMdp1', 'password_confirmation' => 'NouveauMdp1',
        ])->assertOk();

        // L'ancien jeton ne doit plus fonctionner : toutes les sessions sont révoquées.
        $this->withHeader('Authorization', "Bearer {$ancienJeton}")->getJson('/api/v1/auth/me')->assertUnauthorized();

        // L'ancien mot de passe ne fonctionne plus, le nouveau oui.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'AncienMdp1', 'device_name' => 'test'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'NouveauMdp1', 'device_name' => 'test'])->assertOk();
    }

    public function test_une_adresse_inconnue_recoit_la_meme_reponse_que_pour_un_compte_existant(): void
    {
        $reponseInconnue = $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => 'personne@example.com']);
        $connu = $this->creerLivreur();
        $reponseConnue = $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => $connu->email]);

        $reponseInconnue->assertOk();
        $this->assertSame($reponseInconnue->json('data.message'), $reponseConnue->json('data.message'));
    }

    public function test_un_client_ne_peut_pas_demander_de_reinitialisation(): void
    {
        $client = User::factory()->create(['type_utilisateur' => ROLE_CLIENT, 'email' => 'client@example.com', 'statut_compte' => STATUT_COMPTE_ACTIF]);

        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => $client->email])->assertOk();

        Notification::assertNothingSentTo($client);
        $this->assertNull($client->fresh()->password_reset_code);
    }

    public function test_un_mauvais_code_est_refuse_et_compte_comme_une_tentative(): void
    {
        $user = $this->creerLivreur();
        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => $user->email])->assertOk();

        $reponse = $this->postJson('/api/v1/auth/mot-de-passe/reinitialiser', [
            'email' => $user->email, 'code' => '000000', 'password' => 'NouveauMdp1', 'password_confirmation' => 'NouveauMdp1',
        ]);

        $reponse->assertUnprocessable();
        $this->assertSame(1, $user->fresh()->password_reset_tentatives);
        $this->assertTrue(Hash::check('AncienMdp1', $user->fresh()->password), 'le mot de passe ne doit pas changer sur un mauvais code');
    }

    public function test_le_code_est_invalide_au_dela_du_nombre_maximum_de_tentatives(): void
    {
        $user = $this->creerLivreur();
        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => $user->email])->assertOk();
        $code = $this->codeEnvoye($user);

        // Simule PASSWORD_RESET_TENTATIVES_MAX échecs déjà comptabilisés (sans
        // refaire les appels HTTP un par un, pour ne pas dépendre du throttle
        // réseau de la route, mécanisme distinct testé ailleurs).
        $user->forceFill(['password_reset_tentatives' => PASSWORD_RESET_TENTATIVES_MAX])->save();

        // Même avec le BON code, la tentative est refusée : le code a été
        // invalidé après le seuil, il faut en redemander un.
        $this->postJson('/api/v1/auth/mot-de-passe/reinitialiser', [
            'email' => $user->email, 'code' => $code, 'password' => 'NouveauMdp1', 'password_confirmation' => 'NouveauMdp1',
        ])->assertUnprocessable();
        $this->assertNull($user->fresh()->password_reset_code);
    }

    public function test_un_code_expire_est_refuse(): void
    {
        $user = $this->creerLivreur();
        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => $user->email])->assertOk();
        $code = $this->codeEnvoye($user);

        $user->forceFill(['password_reset_expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/v1/auth/mot-de-passe/reinitialiser', [
            'email' => $user->email, 'code' => $code, 'password' => 'NouveauMdp1', 'password_confirmation' => 'NouveauMdp1',
        ])->assertUnprocessable();
    }

    public function test_un_mot_de_passe_trop_faible_est_refuse(): void
    {
        $user = $this->creerLivreur();
        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => $user->email])->assertOk();
        $code = $this->codeEnvoye($user);

        $this->postJson('/api/v1/auth/mot-de-passe/reinitialiser', [
            'email' => $user->email, 'code' => $code, 'password' => 'faible', 'password_confirmation' => 'faible',
        ])->assertUnprocessable();
    }
}
