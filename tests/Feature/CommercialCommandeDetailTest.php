<?php

namespace Tests\Feature;

use App\Models\Commande;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Écran détail commande (app Commercial, onglets Suivi/Information) —
 * GET /commandes/{id} et /commandes/{id}/suivi. Même mécanique que
 * FournisseurCommandeDetailTest (meta.apercu) : avant ce test, seul le
 * Coordinateur/Admin/Fournisseur recevait `meta.apercu`, jamais le
 * Commercial — alors que c'est lui qui a saisi la commande. Retour de test
 * réel : l'écran détail restait bloqué sur "Chargement…" (voir
 * CommandeController::show(), $estStaffOuFournisseur).
 */
class CommercialCommandeDetailTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_le_commercial_voit_le_detail_de_sa_propre_commande_avec_apercu(): void
    {
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponseCreation = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commande = Commande::findOrFail($reponseCreation->json('data.id'));

        $reponse = $this->actingAs($commercial)->getJson("/api/v1/commandes/{$commande->id}");

        $reponse->assertOk();
        $reponse->assertJsonPath('data.id', $commande->id);
        $this->assertNotNull($reponse->json('meta.apercu'));
        $this->assertSame($produit->nom_produit, $reponse->json('meta.apercu.nom_produit'));
    }

    public function test_le_commercial_voit_le_suivi_de_sa_propre_commande(): void
    {
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponseCreation = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commande = Commande::findOrFail($reponseCreation->json('data.id'));

        $this->actingAs($commercial)->getJson("/api/v1/commandes/{$commande->id}/suivi")->assertOk();
    }

    public function test_un_commercial_ne_voit_pas_lapercu_dune_commande_dun_autre(): void
    {
        $commercial = $this->creerCommercial();
        $autreCommercial = $this->creerCommercialTiers();
        $produit = $this->creerProduitPhysique();
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponseCreation = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commande = Commande::findOrFail($reponseCreation->json('data.id'));

        $this->actingAs($autreCommercial)
            ->getJson("/api/v1/commandes/{$commande->id}")
            ->assertForbidden();
    }
}
