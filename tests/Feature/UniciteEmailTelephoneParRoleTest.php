<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Un même email/téléphone peut désormais servir à des comptes de rôles
 * différents — c'est le rôle (type_utilisateur) qui distingue les comptes,
 * pas l'email/téléphone seul (voir migration unicite_email_telephone_par_role
 * et AuthController::login()).
 */
class UniciteEmailTelephoneParRoleTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_le_meme_email_peut_servir_a_deux_comptes_de_roles_differents(): void
    {
        $this->creerLivreur(['email' => 'partage@example.com']);
        $commercial = $this->creerCommercial(['email' => 'partage@example.com']);

        $this->assertNotNull($commercial);
        $this->assertSame(2, User::where('email', 'partage@example.com')->count());
    }

    public function test_le_meme_telephone_peut_servir_a_deux_comptes_de_roles_differents(): void
    {
        $this->creerLivreur(['telephone' => '+225700000050']);
        $fournisseur = $this->creerFournisseur(['telephone' => '+225700000050']);

        $this->assertNotNull($fournisseur);
        $this->assertSame(2, User::where('telephone', '+225700000050')->count());
    }

    public function test_la_contrainte_db_refuse_toujours_un_doublon_dans_le_meme_role(): void
    {
        $this->creerLivreur(['email' => 'doublon@example.com']);

        $this->expectException(QueryException::class);

        User::factory()->create(['type_utilisateur' => ROLE_LIVREUR, 'email' => 'doublon@example.com']);
    }

    public function test_le_login_avec_un_role_explicite_resout_le_bon_compte(): void
    {
        $livreur = $this->creerLivreur(['email' => 'partage-login@example.com']);
        $commercial = $this->creerCommercial(['email' => 'partage-login@example.com']);

        $reponseLivreur = $this->postJson('/api/v1/auth/login', [
            'email' => 'partage-login@example.com',
            'password' => 'password',
            'device_name' => 'test',
            'type_utilisateur' => ROLE_LIVREUR,
        ]);
        $reponseLivreur->assertOk();
        $this->assertSame($livreur->id, $reponseLivreur->json('data.user.id'));

        $reponseCommercial = $this->postJson('/api/v1/auth/login', [
            'email' => 'partage-login@example.com',
            'password' => 'password',
            'device_name' => 'test',
            'type_utilisateur' => ROLE_COMMERCIAL,
        ]);
        $reponseCommercial->assertOk();
        $this->assertSame($commercial->id, $reponseCommercial->json('data.user.id'));
    }

    /**
     * Sans rôle envoyé (cas de l'Admin Web, qui sert Coordinateur ET
     * Administrateur sans distinguer), le comportement historique est
     * conservé : le premier compte non-Client trouvé répond — pas d'erreur,
     * pas de régression par rapport à avant ce changement.
     */
    public function test_le_login_sans_role_garde_le_comportement_historique(): void
    {
        $livreur = $this->creerLivreur(['email' => 'partage-sans-role@example.com']);
        $fournisseur = $this->creerFournisseur(['email' => 'partage-sans-role@example.com']);

        $reponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'partage-sans-role@example.com',
            'password' => 'password',
            'device_name' => 'test',
        ]);

        $reponse->assertOk();
        $this->assertContains($reponse->json('data.user.id'), [$livreur->id, $fournisseur->id]);
    }
}
