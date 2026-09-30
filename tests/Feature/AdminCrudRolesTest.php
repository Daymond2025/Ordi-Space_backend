<?php

namespace Tests\Feature;

use App\Models\Coordinateur;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * CRUD complet Admin sur Coordinateur/Fournisseur/Livreur — même principe que
 * pour un Client (UtilisateurController::provisionner()/changerStatut(),
 * étendu aux 2 rôles manquants + FournisseurController::modifierProfilAdmin(),
 * LivreurController::modifierProfil(), Admin\CoordinateurController).
 */
class AdminCrudRolesTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_ladmin_cree_un_fournisseur_avec_sa_fiche_entreprise(): void
    {
        $admin = $this->creerAdmin();

        $reponse = $this->actingAs($admin)->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'Kouassi', 'prenom' => 'Jean', 'email' => 'nouveau.fournisseur@example.com',
            'password' => 'Motdepasse1', 'type_utilisateur' => ROLE_FOURNISSEUR,
            'nom_entreprise' => 'Kouassi Informatique',
        ]);

        $reponse->assertCreated();
        $userId = $reponse->json('data.id');
        $this->assertDatabaseHas('fournisseurs', ['user_id' => $userId, 'nom_entreprise' => 'Kouassi Informatique']);
        $this->assertTrue(User::find($userId)->hasRole(ROLE_FOURNISSEUR));
    }

    public function test_creer_un_fournisseur_sans_nom_entreprise_echoue(): void
    {
        $admin = $this->creerAdmin();

        $this->actingAs($admin)->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'Kouassi', 'email' => 'sans.entreprise@example.com',
            'password' => 'Motdepasse1', 'type_utilisateur' => ROLE_FOURNISSEUR,
        ])->assertUnprocessable();
    }

    public function test_ladmin_cree_un_livreur(): void
    {
        $admin = $this->creerAdmin();

        $reponse = $this->actingAs($admin)->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'Traore', 'prenom' => 'Ali', 'email' => 'nouveau.livreur@example.com',
            'password' => 'Motdepasse1', 'type_utilisateur' => ROLE_LIVREUR,
            'type_vehicule' => 'moto', 'zone_couverture' => 'Cocody',
        ]);

        $reponse->assertCreated();
        $userId = $reponse->json('data.id');
        $this->assertDatabaseHas('livreurs', ['user_id' => $userId, 'type_vehicule' => 'moto', 'zone_couverture' => 'Cocody']);
        $this->assertTrue(User::find($userId)->hasRole(ROLE_LIVREUR));
    }

    public function test_ladmin_cree_toujours_un_coordinateur(): void
    {
        $admin = $this->creerAdmin();

        $reponse = $this->actingAs($admin)->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'Kone', 'email' => 'nouveau.coordinateur@example.com',
            'password' => 'Motdepasse1', 'type_utilisateur' => ROLE_COORDINATEUR,
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('coordinateurs', ['user_id' => $reponse->json('data.id')]);
    }

    public function test_seul_ladmin_peut_provisionner(): void
    {
        $coordinateur = $this->creerCoordinateur();

        $this->actingAs($coordinateur)->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'X', 'email' => 'refuse@example.com', 'password' => 'Motdepasse1', 'type_utilisateur' => ROLE_LIVREUR,
        ])->assertForbidden();
    }

    public function test_ladmin_modifie_lidentite_de_base_dun_fournisseur(): void
    {
        $admin = $this->creerAdmin();
        $fournisseurUser = $this->creerFournisseur();

        $reponse = $this->actingAs($admin)->patchJson("/api/v1/admin/utilisateurs/{$fournisseurUser->id}", [
            'nom' => 'Nouveau Nom', 'telephone' => '0700000000',
        ]);

        $reponse->assertOk();
        $this->assertSame('Nouveau Nom', $fournisseurUser->fresh()->nom);
        $this->assertSame('0700000000', $fournisseurUser->fresh()->telephone);
    }

    public function test_email_deja_pris_refuse_a_la_modification(): void
    {
        $admin = $this->creerAdmin();
        $fournisseurA = $this->creerFournisseur();
        $fournisseurB = $this->creerFournisseur();

        $this->actingAs($admin)->patchJson("/api/v1/admin/utilisateurs/{$fournisseurB->id}", [
            'email' => $fournisseurA->email,
        ])->assertUnprocessable();
    }

    public function test_ladmin_modifie_la_fiche_entreprise_dun_fournisseur_dont_le_nom(): void
    {
        $admin = $this->creerAdmin();
        $fournisseurUser = $this->creerFournisseur();

        $reponse = $this->actingAs($admin)->patchJson("/api/v1/fournisseurs/{$fournisseurUser->id}/profil", [
            'nom_entreprise' => 'Rebaptisée SARL', 'zone_couverte' => 'Yopougon',
        ]);

        $reponse->assertOk();
        $this->assertDatabaseHas('fournisseurs', ['user_id' => $fournisseurUser->id, 'nom_entreprise' => 'Rebaptisée SARL', 'zone_couverte' => 'Yopougon']);
    }

    public function test_le_fournisseur_lui_meme_ne_peut_pas_utiliser_la_route_admin(): void
    {
        $fournisseurUser = $this->creerFournisseur();

        $this->actingAs($fournisseurUser)
            ->patchJson("/api/v1/fournisseurs/{$fournisseurUser->id}/profil", ['nom_entreprise' => 'Triche SARL'])
            ->assertForbidden();
    }

    public function test_le_coordinateur_ne_peut_pas_modifier_la_fiche_entreprise(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $fournisseurUser = $this->creerFournisseur();

        $this->actingAs($coordinateur)
            ->patchJson("/api/v1/fournisseurs/{$fournisseurUser->id}/profil", ['nom_entreprise' => 'Triche SARL'])
            ->assertForbidden();
    }

    public function test_ladmin_modifie_le_vehicule_et_la_zone_dun_livreur(): void
    {
        $admin = $this->creerAdmin();
        $livreurUser = $this->creerLivreur();

        $reponse = $this->actingAs($admin)->patchJson("/api/v1/coordinateur/livreurs/{$livreurUser->id}/profil", [
            'type_vehicule' => 'voiture', 'zone_couverture' => 'Marcory',
        ]);

        $reponse->assertOk();
        $this->assertDatabaseHas('livreurs', ['user_id' => $livreurUser->id, 'type_vehicule' => 'voiture', 'zone_couverture' => 'Marcory']);
    }

    public function test_le_coordinateur_ne_peut_pas_modifier_le_profil_dun_livreur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $livreurUser = $this->creerLivreur();

        $this->actingAs($coordinateur)
            ->patchJson("/api/v1/coordinateur/livreurs/{$livreurUser->id}/profil", ['type_vehicule' => 'voiture'])
            ->assertForbidden();
    }

    public function test_ladmin_liste_et_consulte_la_fiche_dun_coordinateur(): void
    {
        $admin = $this->creerAdmin();
        $coordinateurUser = $this->creerCoordinateur();

        $liste = $this->actingAs($admin)->getJson('/api/v1/admin/coordinateurs');
        $liste->assertOk();
        $this->assertTrue(collect($liste->json('data.data'))->contains('user_id', $coordinateurUser->id));

        $fiche = $this->actingAs($admin)->getJson("/api/v1/admin/coordinateurs/{$coordinateurUser->id}");
        $fiche->assertOk();
        $this->assertSame($coordinateurUser->id, $fiche->json('data.user_id'));
        $this->assertArrayHasKey('statistiques', $fiche->json('data'));
    }

    public function test_ladmin_modifie_la_fiche_pratique_dun_coordinateur(): void
    {
        $admin = $this->creerAdmin();
        $coordinateurUser = $this->creerCoordinateur();

        $reponse = $this->actingAs($admin)->patchJson("/api/v1/admin/coordinateurs/{$coordinateurUser->id}/profil", [
            'adresse' => 'Rue des Jardins', 'horaires' => '8h-18h', 'zone_couverte' => 'Cocody',
        ]);

        $reponse->assertOk();
        $this->assertDatabaseHas('coordinateurs', [
            'user_id' => $coordinateurUser->id, 'adresse' => 'Rue des Jardins', 'horaires' => '8h-18h', 'zone_couverte' => 'Cocody',
        ]);
    }

    /**
     * "Suppression" = désactivation (changerStatut), même mécanisme déjà
     * utilisé pour un client/commercial — jamais de suppression physique.
     * Vérifie ici que ça fonctionne bien aussi pour fournisseur/livreur/coordinateur.
     */
    public function test_desactiver_un_fournisseur_un_livreur_et_un_coordinateur(): void
    {
        $admin = $this->creerAdmin();
        $fournisseurUser = $this->creerFournisseur();
        $livreurUser = $this->creerLivreur();
        $coordinateurUser = $this->creerCoordinateur();

        foreach ([$fournisseurUser, $livreurUser, $coordinateurUser] as $utilisateur) {
            $this->actingAs($admin)
                ->patchJson("/api/v1/admin/utilisateurs/{$utilisateur->id}/statut", ['statut_compte' => STATUT_COMPTE_DESACTIVE])
                ->assertOk();
            $this->assertSame(STATUT_COMPTE_DESACTIVE, $utilisateur->fresh()->statut_compte);
        }
    }
}
