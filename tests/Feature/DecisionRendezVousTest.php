<?php

namespace Tests\Feature;

use App\Models\DemandeSav;
use App\Models\RendezVous;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * POST /sav/demandes/{id}/decision — boutons Accepter / Refuser / Reporter
 * de l'écran détail du rendez-vous (app Maintenancier).
 */
class DecisionRendezVousTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerDemande(): DemandeSav
    {
        return DemandeSav::create([
            'client_id' => $this->creerClient()->id,
            'description_probleme' => 'Écran noir',
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
            'date_demande' => now(),
        ]);
    }

    public function test_accepter_une_demande_sans_rendez_vous_le_cree_confirme_et_notifie_le_client(): void
    {
        $technicien = $this->creerTechnicien();
        $demande = $this->creerDemande();

        $this->actingAs($technicien)->postJson("/api/v1/sav/demandes/{$demande->id}/decision", [
            'decision' => 'accepter',
            'date_rdv' => now()->addDay()->setTime(10, 10)->toDateTimeString(),
        ])->assertOk()->assertJsonPath('data.statut_rdv', STATUT_RDV_CONFIRME);

        $rdv = $demande->rendezVous()->firstOrFail();
        $this->assertSame($technicien->id, $rdv->technicien_id);
        $this->assertSame(STATUT_DEMANDE_SAV_PLANIFIEE, $demande->fresh()->statut_demande);
        $this->assertDatabaseHas('notifications_ordispace', ['user_id' => $demande->client_id, 'titre' => 'Rendez-vous confirmé']);
    }

    public function test_accepter_sans_rendez_vous_exige_une_date(): void
    {
        $demande = $this->creerDemande();

        $this->actingAs($this->creerTechnicien())->postJson("/api/v1/sav/demandes/{$demande->id}/decision", ['decision' => 'accepter'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['date_rdv']]]);
    }

    public function test_reporter_change_la_date_du_rendez_vous_existant(): void
    {
        $technicien = $this->creerTechnicien();
        $demande = $this->creerDemande();
        $rdv = RendezVous::create(['demande_sav_id' => $demande->id, 'technicien_id' => $technicien->id, 'date_rdv' => now()->addDay(), 'statut_rdv' => STATUT_RDV_DEMANDE]);
        $nouvelleDate = now()->addDays(4)->setTime(15, 0);

        $this->actingAs($technicien)->postJson("/api/v1/sav/demandes/{$demande->id}/decision", [
            'decision' => 'reporter',
            'date_rdv' => $nouvelleDate->toDateTimeString(),
        ])->assertOk();

        $rdv->refresh();
        $this->assertSame(STATUT_RDV_REPORTE, $rdv->statut_rdv);
        $this->assertSame($nouvelleDate->format('Y-m-d H:i'), $rdv->date_rdv->format('Y-m-d H:i'));
    }

    public function test_refuser_puis_plus_aucune_decision_possible(): void
    {
        $technicien = $this->creerTechnicien();
        $demande = $this->creerDemande();

        $this->actingAs($technicien)->postJson("/api/v1/sav/demandes/{$demande->id}/decision", ['decision' => 'refuser'])
            ->assertOk()->assertJsonPath('data.statut_rdv', STATUT_RDV_REFUSE);
        $this->assertSame(STATUT_DEMANDE_SAV_EN_ATTENTE, $demande->fresh()->statut_demande);

        $this->actingAs($technicien)->postJson("/api/v1/sav/demandes/{$demande->id}/decision", [
            'decision' => 'accepter',
            'date_rdv' => now()->addDay()->toDateTimeString(),
        ])->assertUnprocessable();
    }

    public function test_un_autre_technicien_ne_peut_pas_decider_d_un_rendez_vous_qui_n_est_pas_le_sien(): void
    {
        $demande = $this->creerDemande();
        RendezVous::create(['demande_sav_id' => $demande->id, 'technicien_id' => $this->creerTechnicien()->id, 'date_rdv' => now()->addDay(), 'statut_rdv' => STATUT_RDV_DEMANDE]);

        $this->actingAs($this->creerTechnicien())->postJson("/api/v1/sav/demandes/{$demande->id}/decision", ['decision' => 'refuser'])
            ->assertForbidden();
    }

    public function test_le_detail_expose_l_adresse_du_client_et_la_categorie_du_produit(): void
    {
        $demande = $this->creerDemande();

        $this->actingAs($this->creerTechnicien())->getJson("/api/v1/sav/demandes/{$demande->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['client' => ['adresses']]]);
    }
}
