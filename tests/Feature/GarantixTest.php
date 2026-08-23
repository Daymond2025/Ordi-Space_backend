<?php

namespace Tests\Feature;

use App\Models\AbonnementGarantix;
use App\Models\FormuleGarantix;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class GarantixTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_un_client_peut_activer_une_formule_sur_son_ordinateur(): void
    {
        $client = $this->creerClient();
        $ligne = $this->creerAchatLivre($client);
        $formule = FormuleGarantix::create([
            'nom' => 'essentielle', 'libelle_complet' => 'GarantiX Essentielle',
            'prix_annuel' => 10000, 'frequence_interventions' => 3, 'actif' => true,
        ]);

        $reponse = $this->actingAs($client)->postJson('/api/v1/garantix/abonnements', [
            'ligne_commande_id' => $ligne->id,
            'formule_garantix_id' => $formule->id,
            'mode_paiement' => 'especes',
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('abonnements_garantix', [
            'ligne_commande_id' => $ligne->id,
            'client_id' => $client->id,
            'statut' => STATUT_ABONNEMENT_GARANTIX_ACTIF,
            'statut_paiement' => STATUT_PAIEMENT_EN_ATTENTE,
        ]);
    }

    /**
     * Le simple clic du client sur "espèces"/"mobile money" n'active rien :
     * il ne peut pas s'auto-déclarer payeur sans vérification de l'Admin.
     */
    public function test_l_activation_par_le_client_cree_une_demande_en_attente_pas_un_abonnement_actif(): void
    {
        $client = $this->creerClient();
        $ligne = $this->creerAchatLivre($client);
        $formule = FormuleGarantix::create([
            'nom' => 'essentielle', 'libelle_complet' => 'GarantiX Essentielle',
            'prix_annuel' => 10000, 'frequence_interventions' => 3, 'actif' => true,
        ]);

        $this->actingAs($client)->postJson('/api/v1/garantix/abonnements', [
            'ligne_commande_id' => $ligne->id,
            'formule_garantix_id' => $formule->id,
            'mode_paiement' => 'especes',
        ])->assertCreated();

        $abonnement = AbonnementGarantix::where('ligne_commande_id', $ligne->id)->firstOrFail();
        $this->assertFalse($abonnement->estActif());

        $detail = $this->actingAs($client)->getJson("/api/v1/moi/achats/{$ligne->id}");
        $detail->assertOk();
        $this->assertNull($detail->json('data.abonnement_garantix_actif'));
        $this->assertNotNull($detail->json('data.abonnement_garantix_en_attente'));
    }

    public function test_un_admin_peut_confirmer_le_paiement_et_activer_l_abonnement(): void
    {
        $client = $this->creerClient();
        $admin = $this->creerAdmin();
        $ligne = $this->creerAchatLivre($client);
        $formule = FormuleGarantix::create([
            'nom' => 'essentielle', 'libelle_complet' => 'GarantiX Essentielle',
            'prix_annuel' => 10000, 'frequence_interventions' => 3, 'actif' => true,
        ]);

        $this->actingAs($client)->postJson('/api/v1/garantix/abonnements', [
            'ligne_commande_id' => $ligne->id,
            'formule_garantix_id' => $formule->id,
            'mode_paiement' => 'especes',
        ])->assertCreated();

        $abonnement = AbonnementGarantix::where('ligne_commande_id', $ligne->id)->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/v1/garantix/abonnements/{$abonnement->id}/confirmer-paiement")
            ->assertOk();

        $this->assertTrue($abonnement->fresh()->estActif());

        $detail = $this->actingAs($client)->getJson("/api/v1/moi/achats/{$ligne->id}");
        $this->assertNotNull($detail->json('data.abonnement_garantix_actif'));
        $this->assertNull($detail->json('data.abonnement_garantix_en_attente'));
    }

    public function test_un_client_ne_peut_pas_confirmer_lui_meme_son_paiement(): void
    {
        $client = $this->creerClient();
        $ligne = $this->creerAchatLivre($client);
        $formule = FormuleGarantix::create([
            'nom' => 'essentielle', 'libelle_complet' => 'GarantiX Essentielle',
            'prix_annuel' => 10000, 'frequence_interventions' => 3, 'actif' => true,
        ]);

        $this->actingAs($client)->postJson('/api/v1/garantix/abonnements', [
            'ligne_commande_id' => $ligne->id,
            'formule_garantix_id' => $formule->id,
            'mode_paiement' => 'especes',
        ])->assertCreated();

        $abonnement = AbonnementGarantix::where('ligne_commande_id', $ligne->id)->firstOrFail();

        $this->actingAs($client)
            ->patchJson("/api/v1/garantix/abonnements/{$abonnement->id}/confirmer-paiement")
            ->assertForbidden();
    }

    public function test_un_admin_peut_rejeter_une_demande_qui_n_active_alors_jamais_rien(): void
    {
        $client = $this->creerClient();
        $admin = $this->creerAdmin();
        $ligne = $this->creerAchatLivre($client);
        $formule = FormuleGarantix::create([
            'nom' => 'essentielle', 'libelle_complet' => 'GarantiX Essentielle',
            'prix_annuel' => 10000, 'frequence_interventions' => 3, 'actif' => true,
        ]);

        $this->actingAs($client)->postJson('/api/v1/garantix/abonnements', [
            'ligne_commande_id' => $ligne->id,
            'formule_garantix_id' => $formule->id,
            'mode_paiement' => 'especes',
        ])->assertCreated();

        $abonnement = AbonnementGarantix::where('ligne_commande_id', $ligne->id)->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/v1/garantix/abonnements/{$abonnement->id}/rejeter-paiement")
            ->assertOk();

        $this->assertFalse($abonnement->fresh()->estActif());
        $this->assertDatabaseHas('abonnements_garantix', [
            'id' => $abonnement->id, 'statut_paiement' => STATUT_PAIEMENT_ECHOUE,
        ]);
    }

    public function test_un_client_ne_peut_pas_activer_deux_fois_la_meme_ligne(): void
    {
        $client = $this->creerClient();
        $ligne = $this->creerAchatLivre($client);
        $formule = FormuleGarantix::create([
            'nom' => 'essentielle', 'libelle_complet' => 'GarantiX Essentielle',
            'prix_annuel' => 10000, 'frequence_interventions' => 3, 'actif' => true,
        ]);

        $body = ['ligne_commande_id' => $ligne->id, 'formule_garantix_id' => $formule->id, 'mode_paiement' => 'especes'];

        $this->actingAs($client)->postJson('/api/v1/garantix/abonnements', $body)->assertCreated();
        $this->actingAs($client)->postJson('/api/v1/garantix/abonnements', $body)->assertUnprocessable();

        $this->assertSame(1, \App\Models\AbonnementGarantix::where('ligne_commande_id', $ligne->id)->count());
    }

    public function test_un_client_ne_peut_pas_activer_garantix_sur_l_achat_d_un_autre_client(): void
    {
        $proprietaire = $this->creerClient();
        $intrus = $this->creerClient();
        $ligne = $this->creerAchatLivre($proprietaire);
        $formule = FormuleGarantix::create([
            'nom' => 'essentielle', 'libelle_complet' => 'GarantiX Essentielle',
            'prix_annuel' => 10000, 'frequence_interventions' => 3, 'actif' => true,
        ]);

        $this->actingAs($intrus)->postJson('/api/v1/garantix/abonnements', [
            'ligne_commande_id' => $ligne->id,
            'formule_garantix_id' => $formule->id,
            'mode_paiement' => 'especes',
        ])->assertForbidden();
    }

    public function test_les_licences_numeriques_sont_exclues_de_garantix(): void
    {
        $client = $this->creerClient();
        $produitNumerique = $this->creerProduitPhysique(['nom_produit' => 'Licence Office', 'type_livraison' => 'numerique']);
        $ligne = $this->creerAchatLivre($client, $produitNumerique);
        $formule = FormuleGarantix::create([
            'nom' => 'essentielle', 'libelle_complet' => 'GarantiX Essentielle',
            'prix_annuel' => 10000, 'frequence_interventions' => 3, 'actif' => true,
        ]);

        $this->actingAs($client)->postJson('/api/v1/garantix/abonnements', [
            'ligne_commande_id' => $ligne->id,
            'formule_garantix_id' => $formule->id,
            'mode_paiement' => 'especes',
        ])->assertUnprocessable();
    }

    public function test_le_catalogue_public_masque_les_formules_desactivees_aux_clients(): void
    {
        $client = $this->creerClient();
        $admin = $this->creerAdmin();
        FormuleGarantix::create([
            'nom' => 'active', 'libelle_complet' => 'Active', 'prix_annuel' => 10000,
            'frequence_interventions' => 3, 'actif' => true,
        ]);
        FormuleGarantix::create([
            'nom' => 'retiree', 'libelle_complet' => 'Retirée', 'prix_annuel' => 10000,
            'frequence_interventions' => 3, 'actif' => false,
        ]);

        $reponseClient = $this->actingAs($client)->getJson('/api/v1/garantix/formules');
        $reponseClient->assertOk();
        $this->assertCount(1, $reponseClient->json('data'));

        $reponseAdmin = $this->actingAs($admin)->getJson('/api/v1/garantix/formules');
        $this->assertCount(2, $reponseAdmin->json('data'));
    }
}
