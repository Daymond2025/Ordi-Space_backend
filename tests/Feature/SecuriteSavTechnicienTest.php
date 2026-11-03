<?php

namespace Tests\Feature;

use App\Models\DemandeSav;
use App\Models\RendezVous;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Cloisonnement SAV : un technicien n'accède qu'à son périmètre (demandes
 * nouvelles ou à lui), jamais aux rendez-vous d'un autre technicien ; les
 * rôles sans sav.traiter ne voient aucune demande d'autrui.
 */
class SecuriteSavTechnicienTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** Demande dont le rendez-vous est affecté à un autre technicien. */
    private function rendezVousDUnAutre(): RendezVous
    {
        $demande = DemandeSav::create([
            'client_id' => $this->creerClient()->id,
            'description_probleme' => 'Écran noir',
            'statut_demande' => STATUT_DEMANDE_SAV_PLANIFIEE,
            'date_demande' => now(),
        ]);

        return RendezVous::create([
            'demande_sav_id' => $demande->id,
            'technicien_id' => $this->creerTechnicien()->id,
            'date_rdv' => now()->addDay(),
            'statut_rdv' => STATUT_RDV_CONFIRME,
        ]);
    }

    public function test_un_technicien_ne_voit_pas_le_detail_d_une_demande_d_un_autre(): void
    {
        $rdv = $this->rendezVousDUnAutre();

        $this->actingAs($this->creerTechnicien())->getJson("/api/v1/sav/demandes/{$rdv->demande_sav_id}")->assertForbidden();
        $this->actingAs($this->creerTechnicien())->getJson("/api/v1/sav/rendez-vous/{$rdv->id}")->assertForbidden();
    }

    public function test_un_technicien_ne_modifie_pas_le_rendez_vous_ni_l_intervention_d_un_autre(): void
    {
        $rdv = $this->rendezVousDUnAutre();
        $intrus = $this->creerTechnicien();

        $this->actingAs($intrus)->postJson("/api/v1/sav/rendez-vous/{$rdv->id}/intervention", ['statut_intervention' => STATUT_INTERVENTION_TERMINEE])
            ->assertForbidden();
        $this->actingAs($intrus)->patchJson("/api/v1/sav/rendez-vous/{$rdv->id}", ['technicien_id' => $intrus->id])->assertForbidden();
        $this->actingAs($intrus)->patchJson("/api/v1/sav/demandes/{$rdv->demande_sav_id}/statut", ['statut_demande' => STATUT_DEMANDE_SAV_CLOTUREE])
            ->assertForbidden();

        $this->assertDatabaseMissing('interventions', ['rendez_vous_id' => $rdv->id]);
        $this->assertSame(STATUT_DEMANDE_SAV_PLANIFIEE, $rdv->demandeSav->fresh()->statut_demande);
    }

    public function test_un_technicien_n_enregistre_l_intervention_qu_a_son_nom_et_prend_le_rendez_vous_non_affecte(): void
    {
        $rdv = $this->rendezVousDUnAutre();
        $rdv->update(['technicien_id' => null]);
        $technicien = $this->creerTechnicien();

        $this->actingAs($technicien)->postJson("/api/v1/sav/rendez-vous/{$rdv->id}/intervention", [
            'statut_intervention' => STATUT_INTERVENTION_EN_COURS,
            'technicien_id' => $this->creerTechnicien()->id,
        ])->assertOk()->assertJsonPath('data.technicien_id', $technicien->id);

        $this->assertSame($technicien->id, $rdv->fresh()->technicien_id);
    }

    public function test_un_livreur_ne_voit_aucune_demande_sav(): void
    {
        $rdv = $this->rendezVousDUnAutre();

        $this->actingAs($this->creerLivreur())->getJson('/api/v1/sav/demandes')->assertForbidden();
        $this->actingAs($this->creerLivreur())->getJson("/api/v1/sav/demandes/{$rdv->demande_sav_id}")->assertForbidden();
    }

    public function test_un_client_ne_liste_pas_les_techniciens(): void
    {
        $this->actingAs($this->creerClient())->getJson('/api/v1/sav/techniciens')->assertForbidden();
    }
}
