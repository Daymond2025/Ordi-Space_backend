<?php

namespace Tests\Feature;

use App\Models\DemandeSav;
use App\Models\RendezVous;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * App Maintenancier : le technicien de maintenance traite lui-même ses
 * demandes SAV (réception, planification, diagnostic, réparation, statut,
 * historique) — même backend que SavCoordinateurTest, mais acteur
 * technicien, plus le scoping propre à DemandeSavController::index().
 */
class SavTechnicienTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_le_technicien_voit_les_demandes_non_affectees_et_les_siennes(): void
    {
        $technicien = $this->creerTechnicien();
        $autreTechnicien = $this->creerTechnicien();
        $client = $this->creerClient();

        $nouvelle = DemandeSav::create([
            'client_id' => $client->id,
            'description_probleme' => 'Nouvelle, sans rendez-vous',
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
            'date_demande' => now(),
        ]);

        $laSienne = DemandeSav::create([
            'client_id' => $client->id,
            'description_probleme' => 'Planifiée pour moi',
            'statut_demande' => STATUT_DEMANDE_SAV_PLANIFIEE,
            'date_demande' => now(),
        ]);
        RendezVous::create([
            'demande_sav_id' => $laSienne->id,
            'technicien_id' => $technicien->id,
            'date_rdv' => now()->addDay(),
            'lieu' => 'Atelier Abidjan',
        ]);

        $celleDeLAutre = DemandeSav::create([
            'client_id' => $client->id,
            'description_probleme' => 'Planifiée pour un autre technicien',
            'statut_demande' => STATUT_DEMANDE_SAV_PLANIFIEE,
            'date_demande' => now(),
        ]);
        RendezVous::create([
            'demande_sav_id' => $celleDeLAutre->id,
            'technicien_id' => $autreTechnicien->id,
            'date_rdv' => now()->addDay(),
            'lieu' => 'Atelier Abidjan',
        ]);

        $reponse = $this->actingAs($technicien)->getJson('/api/v1/sav/demandes');
        $reponse->assertOk();
        $ids = collect($reponse->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($nouvelle->id));
        $this->assertTrue($ids->contains($laSienne->id));
        $this->assertFalse($ids->contains($celleDeLAutre->id));
    }

    public function test_le_technicien_planifie_un_rendez_vous_pour_lui_meme(): void
    {
        $technicien = $this->creerTechnicien();
        $client = $this->creerClient();
        $demande = DemandeSav::create([
            'client_id' => $client->id,
            'description_probleme' => 'Batterie ne charge plus',
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
            'date_demande' => now(),
        ]);

        $reponse = $this->actingAs($technicien)->postJson("/api/v1/sav/demandes/{$demande->id}/rendez-vous", [
            'technicien_id' => $technicien->id,
            'date_rdv' => now()->addDay()->toDateTimeString(),
            'lieu' => 'Atelier Abidjan',
        ]);

        $reponse->assertCreated();
        $this->assertSame(STATUT_DEMANDE_SAV_PLANIFIEE, $demande->fresh()->statut_demande);
    }

    public function test_le_technicien_diagnostique_puis_termine_la_reparation_et_la_demande_est_resolue(): void
    {
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

        $diagnostic = $this->actingAs($technicien)->postJson("/api/v1/sav/rendez-vous/{$rdv->id}/intervention", [
            'diagnostic' => 'Ventilateur encrassé',
            'statut_intervention' => STATUT_INTERVENTION_EN_COURS,
        ]);
        $diagnostic->assertOk();
        $this->assertDatabaseHas('interventions', [
            'rendez_vous_id' => $rdv->id,
            'technicien_id' => $technicien->id,
            'statut_intervention' => STATUT_INTERVENTION_EN_COURS,
        ]);
        $this->assertSame(STATUT_DEMANDE_SAV_EN_COURS, $demande->fresh()->statut_demande);

        $reparation = $this->actingAs($technicien)->postJson("/api/v1/sav/rendez-vous/{$rdv->id}/intervention", [
            'diagnostic' => 'Ventilateur encrassé',
            'reparation_effectuee' => 'Nettoyage complet',
            'statut_intervention' => STATUT_INTERVENTION_TERMINEE,
            'cout' => 5000,
        ]);
        $reparation->assertOk();

        $this->assertSame(STATUT_DEMANDE_SAV_RESOLUE, $demande->fresh()->statut_demande);
        $this->assertDatabaseHas('interventions', [
            'rendez_vous_id' => $rdv->id,
            'technicien_id' => $technicien->id,
            'statut_intervention' => STATUT_INTERVENTION_TERMINEE,
            'cout' => 5000,
        ]);
    }

    public function test_lhistorique_du_technicien_contient_les_demandes_resolues(): void
    {
        $technicien = $this->creerTechnicien();
        $client = $this->creerClient();
        $demande = DemandeSav::create([
            'client_id' => $client->id,
            'description_probleme' => 'Clavier défectueux',
            'statut_demande' => STATUT_DEMANDE_SAV_RESOLUE,
            'date_demande' => now(),
        ]);
        RendezVous::create([
            'demande_sav_id' => $demande->id,
            'technicien_id' => $technicien->id,
            'date_rdv' => now()->subDay(),
            'lieu' => 'Atelier Abidjan',
        ]);

        $reponse = $this->actingAs($technicien)->getJson('/api/v1/sav/demandes?statut_demande='.STATUT_DEMANDE_SAV_RESOLUE);
        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data.data'));
        $this->assertSame($demande->id, $reponse->json('data.data.0.id'));
    }
}
