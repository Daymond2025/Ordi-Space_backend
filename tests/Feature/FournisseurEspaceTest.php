<?php

namespace Tests\Feature;

use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\LigneCommande;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class FournisseurEspaceTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_le_coordinateur_peut_lister_les_fournisseurs(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $this->creerProduitPhysique(['nom_produit' => 'PC A']);
        $this->creerProduitPhysique(['nom_produit' => 'PC B']);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/fournisseurs');

        $reponse->assertOk();
        $this->assertGreaterThanOrEqual(1, count($reponse->json('data.data')));
        $this->assertArrayHasKey('produits_count', $reponse->json('data.data.0'));
    }

    public function test_le_coordinateur_voit_le_profil_et_les_stats_sans_commission(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 10]);
        $client = $this->creerClient();

        $commandeLivree = Commande::create([
            'client_id' => $client->id, 'commercial_id' => $this->creerAgentIa()->id,
            'canal_vente_id' => CanalVente::firstOrFail()->id, 'statut_commande' => STATUT_COMMANDE_LIVREE,
            'montant_total' => $produit->prix, 'date_commande' => now(),
        ]);
        LigneCommande::create([
            'commande_id' => $commandeLivree->id, 'produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire' => $produit->prix,
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}");

        $reponse->assertOk();
        $reponse->assertJsonPath('data.statistiques.commandes_livrees', 1);
        $reponse->assertJsonPath('data.statistiques.produits_total', 1);
        $this->assertArrayNotHasKey('commission', $reponse->json('data.statistiques'));
        $this->assertArrayHasKey('nom_gerant', $reponse->json('data.fournisseur'));
    }

    public function test_un_fournisseur_ne_peut_pas_lister_les_fournisseurs(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = \App\Models\User::findOrFail($produit->fournisseur_id);

        $this->actingAs($fournisseur)->getJson('/api/v1/fournisseurs')->assertForbidden();
    }

    public function test_un_client_ne_peut_pas_consulter_un_fournisseur(): void
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();

        $this->actingAs($client)->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}")->assertForbidden();
    }
}
