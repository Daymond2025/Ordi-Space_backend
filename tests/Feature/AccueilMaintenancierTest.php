<?php

namespace Tests\Feature;

use App\Models\DemandeSav;
use App\Models\RendezVous;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Accueil de l'app Maintenancier : statut propre au rendez-vous
 * (rendez_vous.statut_rdv) et bascule "En ligne" du technicien
 * (techniciens_maintenance.disponible).
 */
class AccueilMaintenancierTest extends TestCase
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
            'description_probleme' => 'Écran noir au démarrage',
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
            'date_demande' => now(),
        ]);
    }

    public function test_un_rendez_vous_planifie_par_le_technicien_est_confirme_d_office(): void
    {
        $technicien = $this->creerTechnicien();
        $demande = $this->creerDemande();

        $reponse = $this->actingAs($technicien)->postJson("/api/v1/sav/demandes/{$demande->id}/rendez-vous", [
            'technicien_id' => $technicien->id,
            'date_rdv' => now()->addDay()->toDateTimeString(),
            'lieu' => 'Atelier Cocody',
        ]);

        $reponse->assertCreated();
        $this->assertSame(STATUT_RDV_CONFIRME, RendezVous::findOrFail($reponse->json('data.id'))->statut_rdv);
    }

    public function test_terminer_l_intervention_passe_le_rendez_vous_a_termine(): void
    {
        $technicien = $this->creerTechnicien();
        $demande = $this->creerDemande();
        $rdv = RendezVous::create([
            'demande_sav_id' => $demande->id,
            'technicien_id' => $technicien->id,
            'date_rdv' => now()->addDay(),
        ]);

        $this->actingAs($technicien)->postJson("/api/v1/sav/rendez-vous/{$rdv->id}/intervention", [
            'diagnostic' => 'Carte mère',
            'reparation_effectuee' => 'Remplacement',
            'statut_intervention' => STATUT_INTERVENTION_TERMINEE,
        ])->assertOk();

        $this->assertSame(STATUT_RDV_TERMINE, $rdv->fresh()->statut_rdv);
    }

    /**
     * Écran "Diagnostic technique" : champs structurés enregistrés, demande
     * passée en cours, et `diagnostic` résumé pour les écrans existants.
     */
    public function test_le_diagnostic_technique_enregistre_les_champs_detailles(): void
    {
        $technicien = $this->creerTechnicien();
        $demande = $this->creerDemande();
        $rdv = RendezVous::create(['demande_sav_id' => $demande->id, 'technicien_id' => $technicien->id, 'date_rdv' => now()->addDay()]);

        $this->actingAs($technicien)->postJson("/api/v1/sav/rendez-vous/{$rdv->id}/intervention", [
            'statut_intervention' => STATUT_INTERVENTION_EN_COURS,
            'problemes' => ['Batterie défectueuse', 'Surchauffe'],
            'probleme_identifie' => 'Pilotes à réinstaller',
            'cause_panne' => 'Cellules gonflées',
            'niveau_gravite' => 'moyenne',
            'duree_estimee_minutes' => 120,
        ])->assertOk()
            ->assertJsonPath('data.problemes', ['Batterie défectueuse', 'Surchauffe'])
            ->assertJsonPath('data.niveau_gravite', 'moyenne')
            ->assertJsonPath('data.duree_estimee_minutes', 120)
            ->assertJsonPath('data.diagnostic', 'Batterie défectueuse, Surchauffe — Pilotes à réinstaller — Cause : Cellules gonflées');

        $this->assertSame(STATUT_DEMANDE_SAV_EN_COURS, $demande->fresh()->statut_demande);

        $this->actingAs($technicien)->postJson("/api/v1/sav/rendez-vous/{$rdv->id}/intervention", [
            'statut_intervention' => STATUT_INTERVENTION_EN_COURS,
            'niveau_gravite' => 'urgente',
        ])->assertUnprocessable();
    }

    /** Écran "Marquer terminé" : prestations, pièces, fin, note — et résumé `reparation_effectuee`. */
    public function test_marquer_termine_enregistre_prestations_pieces_et_note(): void
    {
        $technicien = $this->creerTechnicien();
        $demande = $this->creerDemande();
        $rdv = RendezVous::create(['demande_sav_id' => $demande->id, 'technicien_id' => $technicien->id, 'date_rdv' => now()->addDay()]);

        $this->actingAs($technicien)->postJson("/api/v1/sav/rendez-vous/{$rdv->id}/intervention", [
            'statut_intervention' => STATUT_INTERVENTION_TERMINEE,
            'prestations' => ['Nettoyage', 'Écran'],
            'pieces_remplacees' => ['Écran'],
            'date_fin' => now()->toDateTimeString(),
            'duree_reelle_minutes' => 90,
            'note_fin' => 'Ordinateur en bon état général.',
        ])->assertOk()
            ->assertJsonPath('data.prestations', ['Nettoyage', 'Écran'])
            ->assertJsonPath('data.duree_reelle_minutes', 90)
            ->assertJsonPath('data.reparation_effectuee', 'Nettoyage, Écran — Pièces remplacées : Écran');

        $this->assertSame(STATUT_DEMANDE_SAV_RESOLUE, $demande->fresh()->statut_demande);
    }

    /** Boutons "Mise en attente" puis "Annulée" de l'écran intervention en cours. */
    public function test_mettre_en_attente_puis_annuler_une_intervention(): void
    {
        $technicien = $this->creerTechnicien();
        $demande = $this->creerDemande();
        $rdv = RendezVous::create(['demande_sav_id' => $demande->id, 'technicien_id' => $technicien->id, 'date_rdv' => now()->addDay()]);
        $url = "/api/v1/sav/rendez-vous/{$rdv->id}/intervention";

        $this->actingAs($technicien)->postJson($url, ['statut_intervention' => STATUT_INTERVENTION_EN_COURS, 'problemes' => ['Surchauffe']])->assertOk();

        $this->actingAs($technicien)->postJson($url, ['statut_intervention' => STATUT_INTERVENTION_PLANIFIEE])->assertOk();
        $this->assertSame(STATUT_DEMANDE_SAV_PLANIFIEE, $demande->fresh()->statut_demande);
        $this->assertSame(['Surchauffe'], $rdv->intervention()->first()->problemes, 'le diagnostic ne doit pas être effacé');

        $this->actingAs($technicien)->postJson($url, ['statut_intervention' => STATUT_INTERVENTION_ANNULEE])->assertOk();
        $this->assertSame(STATUT_DEMANDE_SAV_CLOTUREE, $demande->fresh()->statut_demande);
    }

    public function test_la_liste_des_demandes_expose_le_statut_rdv_et_la_categorie_du_produit(): void
    {
        $technicien = $this->creerTechnicien();
        $demande = $this->creerDemande();
        RendezVous::create([
            'demande_sav_id' => $demande->id,
            'technicien_id' => $technicien->id,
            'date_rdv' => now()->addDay(),
            'statut_rdv' => STATUT_RDV_REPORTE,
        ]);

        $reponse = $this->actingAs($technicien)->getJson('/api/v1/sav/demandes');

        $reponse->assertOk();
        $this->assertSame(STATUT_RDV_REPORTE, $reponse->json('data.data.0.rendez_vous.0.statut_rdv'));
        $this->assertArrayHasKey('intervention', $reponse->json('data.data.0.rendez_vous.0'));
    }

    public function test_le_technicien_bascule_sa_disponibilite_et_le_profil_l_expose(): void
    {
        $technicien = $this->creerTechnicien();

        $this->actingAs($technicien)->getJson('/api/v1/moi/profil')->assertJsonPath('data.disponible', true);

        $this->actingAs($technicien)->patchJson('/api/v1/moi/disponibilite', ['disponible' => false])
            ->assertOk()
            ->assertJsonPath('data.disponible', false);

        $this->assertFalse($technicien->technicien->fresh()->disponible);
    }
}
