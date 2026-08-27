<?php

namespace Tests\Feature;

use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class CommandeCoordinateurTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommandeEnAttente(): Commande
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        return Commande::findOrFail($reponse->json('data.id'));
    }

    public function test_le_defaut_reste_en_attente_seulement_pour_le_coordinateur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommandeEnAttente();
        $commande->update(['statut_commande' => STATUT_COMMANDE_LIVREE]);
        $this->creerCommandeEnAttente();

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/commandes');

        $reponse->assertOk();
        $statuts = collect($reponse->json('data.data'))->pluck('statut_commande')->unique();
        $this->assertEquals([STATUT_COMMANDE_EN_ATTENTE], $statuts->all());
    }

    public function test_statut_tous_leve_la_restriction_pour_le_coordinateur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommandeEnAttente();
        $commande->update(['statut_commande' => STATUT_COMMANDE_LIVREE]);
        $this->creerCommandeEnAttente();

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/commandes?statut=tous');

        $reponse->assertOk();
        $this->assertCount(2, $reponse->json('data.data'));
    }

    public function test_le_coordinateur_peut_signaler_un_probleme_puis_reprendre(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommandeEnAttente();

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/traiter-probleme", [
            'statut_commande' => STATUT_COMMANDE_NUMERO_INCORRECT, 'motif' => 'Numéro à 9 chiffres',
        ])->assertOk();

        $this->assertDatabaseHas('commandes', ['id' => $commande->id, 'statut_commande' => STATUT_COMMANDE_NUMERO_INCORRECT]);

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/traiter-probleme", [
            'statut_commande' => STATUT_COMMANDE_EN_ATTENTE,
        ])->assertOk();

        $this->assertDatabaseHas('commandes', ['id' => $commande->id, 'statut_commande' => STATUT_COMMANDE_EN_ATTENTE]);
    }

    public function test_annuler_depuis_un_statut_probleme_restocke_le_produit(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5]);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
        ]);
        $commande = Commande::findOrFail($reponse->json('data.id'));
        $this->assertEquals(3, $produit->fresh()->quantite_stock);

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/traiter-probleme", [
            'statut_commande' => STATUT_COMMANDE_CLIENT_INJOIGNABLE,
        ])->assertOk();

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/traiter-probleme", [
            'statut_commande' => STATUT_COMMANDE_ANNULEE,
        ])->assertOk();

        $this->assertEquals(5, $produit->fresh()->quantite_stock);
    }

    public function test_une_transition_non_autorisee_est_rejetee(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommandeEnAttente();
        $commande->update(['statut_commande' => STATUT_COMMANDE_LIVREE]);

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/traiter-probleme", [
            'statut_commande' => STATUT_COMMANDE_REPORTEE,
        ])->assertUnprocessable();
    }

    public function test_un_commercial_ne_peut_pas_traiter_un_probleme(): void
    {
        $commercial = $this->creerCommercial();
        $commande = $this->creerCommandeEnAttente();

        $this->actingAs($commercial)->postJson("/api/v1/commandes/{$commande->id}/traiter-probleme", [
            'statut_commande' => STATUT_COMMANDE_REPORTEE,
        ])->assertForbidden();
    }

    public function test_valider_reste_refuse_depuis_un_statut_probleme(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommandeEnAttente();
        $commande->update(['statut_commande' => STATUT_COMMANDE_NUMERO_INCORRECT]);

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/valider")
            ->assertUnprocessable();
    }

    public function test_le_coordinateur_peut_enregistrer_une_vente_avec_le_bon_canal(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();
        $canalWhatsapp = CanalVente::where('nom_canal', 'WhatsApp')->firstOrFail();

        $client = $this->actingAs($coordinateur)->postJson('/api/v1/clients/creation-rapide', [
            'nom' => 'Yao', 'telephone' => '0700000030',
        ]);
        $client->assertCreated();
        $clientId = $client->json('data.id');

        $adresse = $this->creerAdresseAvecLocalite(User::findOrFail($clientId));

        $commande = $this->actingAs($coordinateur)->postJson('/api/v1/commandes', [
            'client_id' => $clientId,
            'canal_vente_id' => $canalWhatsapp->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        $commande->assertCreated();
        $this->assertDatabaseHas('commandes', [
            'id' => $commande->json('data.id'), 'client_id' => $clientId, 'canal_vente_id' => $canalWhatsapp->id,
        ]);
    }

    public function test_show_enrichi_pour_coordinateur_pas_pour_le_client(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommandeEnAttente();

        $reponseCoordinateur = $this->actingAs($coordinateur)->getJson("/api/v1/commandes/{$commande->id}");
        $reponseCoordinateur->assertOk();
        $this->assertArrayHasKey('client', $reponseCoordinateur->json('data'));
        $this->assertArrayHasKey('commercial', $reponseCoordinateur->json('data'));

        $proprietaire = User::findOrFail($commande->client_id);
        $reponseClient = $this->actingAs($proprietaire)->getJson("/api/v1/commandes/{$commande->id}");
        $reponseClient->assertOk();
        $this->assertArrayNotHasKey('commercial', $reponseClient->json('data'));
    }
}
