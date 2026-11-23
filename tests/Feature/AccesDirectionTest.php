<?php

namespace Tests\Feature;

use App\Models\Administrateur;
use App\Models\Coordinateur;
use App\Models\User;
use Database\Seeders\AccesDirectionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Comptes supprimés depuis l'Admin (statut « supprime », la ligne reste en
 * base) : leur e-mail redevient disponible. E-mail de la direction : on peut
 * toujours lui créer un accès, même déjà pris.
 */
class AccesDirectionTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    private const EMAIL_DIRECTION = 'direction@test.local';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.direction.email' => self::EMAIL_DIRECTION]);
    }

    private function compte(string $email, string $role, string $statut): User
    {
        $user = User::factory()->create(['email' => $email, 'type_utilisateur' => $role, 'statut_compte' => $statut]);
        if ($role === ROLE_COORDINATEUR) {
            Coordinateur::create(['user_id' => $user->id]);
        }

        return $user;
    }

    public function test_l_email_d_un_coordinateur_supprime_redevient_disponible(): void
    {
        $ancien = $this->compte('ancien.coord@test.local', ROLE_COORDINATEUR, STATUT_COMPTE_SUPPRIME);

        $reponse = $this->actingAs($this->creerAdmin())->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'Nouveau', 'email' => 'ancien.coord@test.local', 'password' => 'MotDePasse1', 'type_utilisateur' => ROLE_COORDINATEUR,
        ]);

        $reponse->assertCreated();
        $this->assertNotSame($ancien->id, $reponse->json('data.id'));
        $this->assertSame("supprime-{$ancien->id}.ancien.coord@test.local", $ancien->fresh()->email);
        $this->assertSame(STATUT_COMPTE_SUPPRIME, $ancien->fresh()->statut_compte);
    }

    public function test_un_email_actif_ordinaire_reste_refuse(): void
    {
        $this->compte('pris@test.local', ROLE_COORDINATEUR, STATUT_COMPTE_ACTIF);

        $this->actingAs($this->creerAdmin())->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'X', 'email' => 'pris@test.local', 'password' => 'MotDePasse1', 'type_utilisateur' => ROLE_COORDINATEUR,
        ])->assertUnprocessable();
    }

    public function test_l_email_de_la_direction_deja_pris_met_a_jour_le_compte(): void
    {
        $existant = $this->compte(self::EMAIL_DIRECTION, ROLE_COORDINATEUR, STATUT_COMPTE_SUSPENDU);

        $reponse = $this->actingAs($this->creerAdmin())->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'CEO', 'email' => 'Direction@Test.local', 'password' => 'NouveauMdp1', 'type_utilisateur' => ROLE_COORDINATEUR,
        ]);

        $reponse->assertCreated();
        $this->assertSame($existant->id, $reponse->json('data.id'));
        $existant->refresh();
        $this->assertSame(STATUT_COMPTE_ACTIF, $existant->statut_compte);
        $this->assertSame('CEO', $existant->nom);
        $this->assertTrue(Hash::check('NouveauMdp1', $existant->password));
        $this->assertSame(1, User::where('email', self::EMAIL_DIRECTION)->where('type_utilisateur', ROLE_COORDINATEUR)->count());
    }

    public function test_super_admin_avec_l_email_d_un_admin_supprime_ou_de_la_direction(): void
    {
        $superAdmin = $this->creerAdmin();
        $supprime = $this->compte('ancien.admin@test.local', ROLE_ADMINISTRATEUR, STATUT_COMPTE_SUPPRIME);

        $this->actingAs($superAdmin)->postJson('/api/v1/admin/administrateurs', [
            'nom' => 'Nouvel admin', 'email' => 'ancien.admin@test.local', 'password' => 'MotDePasse1', 'role' => 'admin',
        ])->assertCreated();
        $this->assertStringStartsWith("supprime-{$supprime->id}.", $supprime->fresh()->email);

        $direction = $this->compte(self::EMAIL_DIRECTION, ROLE_ADMINISTRATEUR, STATUT_COMPTE_ACTIF);
        $reponse = $this->actingAs($superAdmin)->postJson('/api/v1/admin/administrateurs', [
            'nom' => 'CEO', 'email' => self::EMAIL_DIRECTION, 'password' => 'NouveauMdp1', 'role' => 'super_admin',
        ]);

        $reponse->assertCreated();
        $this->assertSame($direction->id, $reponse->json('data.id'));
        $this->assertTrue(Administrateur::where('user_id', $direction->id)->value('est_super_admin'));
        $this->assertTrue(Hash::check('NouveauMdp1', $direction->fresh()->password));
    }

    public function test_le_seeder_reactive_les_comptes_supprimes_de_la_direction(): void
    {
        $admin = $this->compte(self::EMAIL_DIRECTION, ROLE_ADMINISTRATEUR, STATUT_COMPTE_SUPPRIME);
        $coordinateur = $this->compte(self::EMAIL_DIRECTION, ROLE_COORDINATEUR, STATUT_COMPTE_SUPPRIME);

        foreach (['DIRECTION_EMAIL' => self::EMAIL_DIRECTION, 'DIRECTION_PASSWORD' => 'Essai@2030', 'DIRECTION_NOM' => 'CEO'] as $cle => $valeur) {
            putenv("{$cle}={$valeur}");
            $_ENV[$cle] = $_SERVER[$cle] = $valeur;
        }

        try {
            $this->seed(AccesDirectionSeeder::class);
        } finally {
            foreach (['DIRECTION_EMAIL', 'DIRECTION_PASSWORD', 'DIRECTION_NOM'] as $cle) {
                putenv($cle);
                unset($_ENV[$cle], $_SERVER[$cle]);
            }
        }

        foreach ([$admin, $coordinateur] as $compte) {
            $compte->refresh();
            $this->assertSame(STATUT_COMPTE_ACTIF, $compte->statut_compte);
            $this->assertTrue(Hash::check('Essai@2030', $compte->password));
        }
        $this->assertTrue(Administrateur::where('user_id', $admin->id)->value('est_super_admin'));
        $this->assertSame(2, User::where('email', self::EMAIL_DIRECTION)->count());
    }
}
