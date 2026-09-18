<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /auth/register — auto-inscription Fournisseur/Commercial/Livreur
 * (voir roles_auto_inscription()). Couvre en particulier la normalisation du
 * téléphone avant contrôle d'unicité (AuthController::register()), absente
 * jusqu'ici et qui faisait planter l'inscription sur un doublon (500 brut au
 * lieu d'une erreur de validation propre).
 */
class AuthRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function donneesLivreur(array $override = []): array
    {
        return array_merge([
            'nom' => 'Koffi',
            'prenom' => 'Jean',
            'telephone' => '+225700000099',
            'email' => 'jean.koffi@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'type_utilisateur' => ROLE_LIVREUR,
        ], $override);
    }

    public function test_un_livreur_peut_s_auto_inscrire(): void
    {
        $reponse = $this->postJson('/api/v1/auth/register', $this->donneesLivreur());

        $reponse->assertCreated();
        $reponse->assertJsonPath('data.user.type_utilisateur', ROLE_LIVREUR);
        $this->assertTrue(User::where('email', 'jean.koffi@example.com')->exists());
    }

    public function test_l_inscription_est_rejetee_si_l_email_existe_deja(): void
    {
        $this->postJson('/api/v1/auth/register', $this->donneesLivreur())->assertCreated();

        $reponse = $this->postJson('/api/v1/auth/register', $this->donneesLivreur([
            'telephone' => '+2250700000098',
        ]));

        $reponse->assertUnprocessable();
        $this->assertArrayHasKey('email', $reponse->json('error.fields'));
    }

    /**
     * Le bug corrigé : un même numéro saisi sous un format différent
     * (espaces, préfixe local) devait planter en 500 (contrainte unique DB)
     * au lieu de renvoyer une erreur de validation propre sur "telephone".
     */
    public function test_l_inscription_est_rejetee_proprement_si_le_telephone_existe_deja_sous_un_autre_format(): void
    {
        $this->postJson('/api/v1/auth/register', $this->donneesLivreur())->assertCreated();

        $reponse = $this->postJson('/api/v1/auth/register', $this->donneesLivreur([
            'email' => 'autre.livreur@example.com',
            'telephone' => '07 00 00 00 99',
        ]));

        $reponse->assertUnprocessable();
        $this->assertArrayHasKey('telephone', $reponse->json('error.fields'));
    }
}
