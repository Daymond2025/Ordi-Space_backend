<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class AdministrateurCrudTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_un_super_admin_peut_creer_un_admin_restreint(): void
    {
        $superAdmin = $this->creerAdmin();

        $reponse = $this->actingAs($superAdmin)->postJson('/api/v1/admin/administrateurs', [
            'nom' => 'Kouassi', 'prenom' => 'Awa', 'email' => 'awa.kouassi@test.local',
            'telephone' => '0102030405', 'password' => 'MotDePasse1',
            'role' => 'admin', 'espaces' => ['clients', 'commandes'],
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'awa.kouassi@test.local', 'type_utilisateur' => ROLE_ADMINISTRATEUR]);
        $this->assertDatabaseHas('administrateurs', [
            'user_id' => $reponse->json('data.id'),
            'est_super_admin' => false,
        ]);
        $this->assertSame(['clients', 'commandes'], $reponse->json('data.administrateur.espaces_autorises'));
    }

    public function test_un_super_admin_peut_creer_un_autre_super_admin(): void
    {
        $superAdmin = $this->creerAdmin();

        $reponse = $this->actingAs($superAdmin)->postJson('/api/v1/admin/administrateurs', [
            'nom' => 'Diabate', 'email' => 'diabate@test.local', 'password' => 'MotDePasse1', 'role' => 'super_admin',
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('administrateurs', ['user_id' => $reponse->json('data.id'), 'est_super_admin' => true]);
    }

    public function test_un_admin_restreint_ne_peut_pas_creer_un_admin(): void
    {
        $adminRestreint = $this->creerAdminRestreint(['clients']);

        $this->actingAs($adminRestreint)->postJson('/api/v1/admin/administrateurs', [
            'nom' => 'X', 'email' => 'x@test.local', 'password' => 'MotDePasse1', 'role' => 'admin',
        ])->assertForbidden();
    }

    public function test_le_super_admin_peut_lister_et_voir_les_admins(): void
    {
        $superAdmin = $this->creerAdmin();
        $adminRestreint = $this->creerAdminRestreint(['fournisseurs']);

        $liste = $this->actingAs($superAdmin)->getJson('/api/v1/admin/administrateurs');
        $liste->assertOk();
        $this->assertGreaterThanOrEqual(2, count($liste->json('data')));

        $detail = $this->actingAs($superAdmin)->getJson("/api/v1/admin/administrateurs/{$adminRestreint->id}");
        $detail->assertOk();
        $this->assertSame(['fournisseurs'], $detail->json('data.administrateur.espaces_autorises'));
    }

    public function test_un_admin_restreint_ne_peut_pas_lister_les_admins(): void
    {
        $adminRestreint = $this->creerAdminRestreint();

        $this->actingAs($adminRestreint)->getJson('/api/v1/admin/administrateurs')->assertForbidden();
    }

    public function test_le_super_admin_peut_modifier_le_role_et_les_espaces_dun_admin(): void
    {
        $superAdmin = $this->creerAdmin();
        $adminRestreint = $this->creerAdminRestreint(['clients']);

        $this->actingAs($superAdmin)->patchJson("/api/v1/admin/administrateurs/{$adminRestreint->id}", [
            'role' => 'admin', 'espaces' => ['clients', 'commandes', 'finance'],
        ])->assertOk();

        $this->assertDatabaseHas('administrateurs', [
            'user_id' => $adminRestreint->id,
            'espaces_autorises' => json_encode(['clients', 'commandes', 'finance']),
        ]);
    }

    public function test_un_super_admin_ne_peut_pas_se_retirer_a_lui_meme_le_role_super_admin(): void
    {
        $superAdmin = $this->creerAdmin();

        $this->actingAs($superAdmin)->patchJson("/api/v1/admin/administrateurs/{$superAdmin->id}", [
            'role' => 'admin', 'espaces' => ['clients'],
        ])->assertStatus(422);
    }

    public function test_un_admin_ne_peut_pas_modifier_son_propre_statut(): void
    {
        $superAdmin = $this->creerAdmin();

        $this->actingAs($superAdmin)->patchJson("/api/v1/admin/utilisateurs/{$superAdmin->id}/statut", [
            'statut_compte' => STATUT_COMPTE_DESACTIVE,
        ])->assertStatus(422);
    }

    public function test_un_admin_restreint_sans_espace_clients_ne_peut_pas_consulter_les_clients(): void
    {
        $adminRestreint = $this->creerAdminRestreint(['commandes']);

        $this->actingAs($adminRestreint)->getJson('/api/v1/admin/clients')->assertForbidden();
    }

    public function test_un_admin_restreint_avec_espace_clients_peut_consulter_les_clients(): void
    {
        $adminRestreint = $this->creerAdminRestreint(['clients']);

        $this->actingAs($adminRestreint)->getJson('/api/v1/admin/clients')->assertOk();
    }

    public function test_un_super_admin_garde_acces_a_tous_les_espaces(): void
    {
        $superAdmin = $this->creerAdmin();

        $this->actingAs($superAdmin)->getJson('/api/v1/admin/clients')->assertOk();
        $this->actingAs($superAdmin)->getJson('/api/v1/admin/commandes')->assertOk();
        $this->actingAs($superAdmin)->getJson('/api/v1/admin/coordinateurs')->assertOk();
    }

    public function test_un_coordinateur_nest_jamais_concerne_par_les_espaces_admin(): void
    {
        // Un coordinateur (pas de type_utilisateur administrateur) passe
        // toujours VerifieEspaceAdmin sans y être soumis — ses propres
        // permissions Spatie restent seules décisionnaires.
        $coordinateur = $this->creerCoordinateur();

        $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/commerciaux')->assertOk();
    }
}
