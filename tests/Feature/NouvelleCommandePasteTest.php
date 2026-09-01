<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Commande;
use App\Models\Localite;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Flux "création de commande par copier-coller" (bouton "+" de la discussion
 * produit, Espace Coordinateur) : création d'adresse pour un tiers,
 * prévisualisation des frais de livraison, et persistance de "notes".
 */
class NouvelleCommandePasteTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_le_coordinateur_cree_une_adresse_pour_un_client_avec_ville_derivee_commune_abidjan(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $client = $this->creerClient();
        $cocody = Localite::where('nom', 'Cocody')->firstOrFail();

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/clients/{$client->id}/adresses", [
            'localite_id' => $cocody->id,
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('adresses', [
            'client_id' => $client->id,
            'localite_id' => $cocody->id,
            'ville' => 'Abidjan',
        ]);
    }

    public function test_ville_derivee_est_le_nom_de_la_localite_pour_une_ville_hors_abidjan(): void
    {
        $commercial = $this->creerCommercial();
        $client = $this->creerClient();
        $bouake = Localite::where('nom', 'Bouaké')->firstOrFail();

        $reponse = $this->actingAs($commercial)->postJson("/api/v1/clients/{$client->id}/adresses", [
            'localite_id' => $bouake->id,
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('adresses', [
            'client_id' => $client->id,
            'localite_id' => $bouake->id,
            'ville' => 'Bouaké',
        ]);
    }

    public function test_un_client_ne_peut_pas_creer_une_adresse_pour_un_autre(): void
    {
        $client = $this->creerClient();
        $autreClient = $this->creerClient();
        $cocody = Localite::where('nom', 'Cocody')->firstOrFail();

        $this->actingAs($client)->postJson("/api/v1/clients/{$autreClient->id}/adresses", [
            'localite_id' => $cocody->id,
        ])->assertForbidden();
    }

    public function test_previsualise_le_frais_de_livraison_reel(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(); // barème Cocody = 2000, cf. helper
        $cocody = Localite::where('nom', 'Cocody')->firstOrFail();

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/frais-livraison?localite_id={$cocody->id}");

        $reponse->assertOk();
        $this->assertEquals(2000, $reponse->json('data.frais_livraison'));
    }

    public function test_rejette_si_aucun_bareme_n_est_defini_pour_cette_localite(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(); // pas de barème pour Bouaké
        $bouake = Localite::where('nom', 'Bouaké')->firstOrFail();

        $this->actingAs($coordinateur)
            ->getJson("/api/v1/produits/{$produit->id}/frais-livraison?localite_id={$bouake->id}")
            ->assertUnprocessable();
    }

    public function test_notes_est_persiste_et_expose_dans_l_apercu(): void
    {
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'notes' => 'Livrer après 18h, appeler avant.',
        ]);

        $reponse->assertCreated();
        $commande = Commande::findOrFail($reponse->json('data.id'));
        $this->assertSame('Livrer après 18h, appeler avant.', $commande->notes);

        $coordinateur = $this->creerCoordinateur();
        $show = $this->actingAs($coordinateur)->getJson("/api/v1/commandes/{$commande->id}");
        $this->assertSame('Livrer après 18h, appeler avant.', $show->json('meta.apercu.notes'));
    }
}
