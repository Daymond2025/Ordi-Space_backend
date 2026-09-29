<?php

namespace Tests\Feature;

use App\Models\DemandeSav;
use App\Models\RendezVous;
use App\Models\TechnicienMaintenance;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * "SAV / Dépannages" (Compte, app Coordinateur) — le coordinateur doit
 * pouvoir consulter les demandes SAV des clients, planifier un rendez-vous
 * technicien et enregistrer le résultat de l'intervention, exactement comme
 * un technicien de maintenance (voir PERMISSION_SAV_TRAITER,
 * RolesAndPermissionsSeeder). Aucun écran n'existait avant cette passe.
 */
class SavCoordinateurTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerTechnicien(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_TECHNICIEN_MAINTENANCE]);
        $user->assignRole(ROLE_TECHNICIEN_MAINTENANCE);
        TechnicienMaintenance::create(['user_id' => $user->id, 'specialite' => 'Matériel']);

        return $user;
    }

    public function test_le_coordinateur_voit_la_liste_des_demandes_sav(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $client = $this->creerClient();
        DemandeSav::create([
            'client_id' => $client->id,
            'description_probleme' => 'Écran ne s\'allume plus',
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
            'date_demande' => now(),
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/sav/demandes');
        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data.data'));
    }

    public function test_le_coordinateur_voit_la_liste_des_techniciens(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $this->creerTechnicien();

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/sav/techniciens');
        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data'));
    }

    public function test_le_coordinateur_planifie_un_rendez_vous_et_la_demande_passe_planifiee(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $technicien = $this->creerTechnicien();
        $client = $this->creerClient();
        $demande = DemandeSav::create([
            'client_id' => $client->id,
            'description_probleme' => 'Batterie ne charge plus',
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
            'date_demande' => now(),
        ]);

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/sav/demandes/{$demande->id}/rendez-vous", [
            'technicien_id' => $technicien->id,
            'date_rdv' => now()->addDay()->toDateTimeString(),
            'lieu' => 'Atelier Abidjan',
        ]);

        $reponse->assertCreated();
        $this->assertSame(STATUT_DEMANDE_SAV_PLANIFIEE, $demande->fresh()->statut_demande);
    }

    public function test_le_coordinateur_enregistre_une_intervention_terminee_et_la_demande_est_resolue(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $technicien = $this->creerTechnicien();
        $client = $this->creerClient();
        $demande = DemandeSav::create([
            'client_id' => $client->id,
            'description_probleme' => 'Ventilateur bruyant',
            'statut_demande' => STATUT_DEMANDE_SAV_PLANIFIEE,
            'date_demande' => now(),
        ]);
        $rdv = RendezVous::create([
            'demande_sav_id' => $demande->id,
            'technicien_id' => $technicien->id,
            'date_rdv' => now()->addDay(),
            'lieu' => 'Atelier Abidjan',
        ]);

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/sav/rendez-vous/{$rdv->id}/intervention", [
            'diagnostic' => 'Ventilateur encrassé',
            'reparation_effectuee' => 'Nettoyage complet',
            'statut_intervention' => STATUT_INTERVENTION_TERMINEE,
            'cout' => 5000,
        ]);

        $reponse->assertOk();
        $this->assertSame(STATUT_DEMANDE_SAV_RESOLUE, $demande->fresh()->statut_demande);
        $this->assertDatabaseHas('interventions', [
            'rendez_vous_id' => $rdv->id,
            'technicien_id' => $technicien->id,
            'statut_intervention' => 'terminee',
        ]);
    }

    public function test_le_coordinateur_change_le_statut_d_une_demande(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $client = $this->creerClient();
        $demande = DemandeSav::create([
            'client_id' => $client->id,
            'description_probleme' => 'Clavier défectueux',
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
            'date_demande' => now(),
        ]);

        $reponse = $this->actingAs($coordinateur)->patchJson("/api/v1/sav/demandes/{$demande->id}/statut", [
            'statut_demande' => STATUT_DEMANDE_SAV_CLOTUREE,
        ]);

        $reponse->assertOk();
        $this->assertSame(STATUT_DEMANDE_SAV_CLOTUREE, $demande->fresh()->statut_demande);
    }

    public function test_un_client_ne_peut_pas_planifier_de_rendez_vous(): void
    {
        $client = $this->creerClient();
        $demande = DemandeSav::create([
            'client_id' => $client->id,
            'description_probleme' => 'Test',
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
            'date_demande' => now(),
        ]);

        $this->actingAs($client)->postJson("/api/v1/sav/demandes/{$demande->id}/rendez-vous", [
            'date_rdv' => now()->addDay()->toDateTimeString(),
        ])->assertForbidden();
    }

    public function test_un_client_voit_uniquement_ses_propres_demandes(): void
    {
        $clientA = $this->creerClient();
        $clientB = $this->creerClient();
        DemandeSav::create([
            'client_id' => $clientA->id, 'description_probleme' => 'A',
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE, 'date_demande' => now(),
        ]);
        DemandeSav::create([
            'client_id' => $clientB->id, 'description_probleme' => 'B',
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE, 'date_demande' => now(),
        ]);

        $reponse = $this->actingAs($clientA)->getJson('/api/v1/sav/demandes');
        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data.data'));
    }
}
