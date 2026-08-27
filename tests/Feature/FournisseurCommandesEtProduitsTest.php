<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class FournisseurCommandesEtProduitsTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_liste_des_produits_scopee_au_bon_fournisseur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produitA = $this->creerProduitPhysique();
        $produitB = $this->creerProduitPhysique();

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$produitA->fournisseur_id}/produits");

        $reponse->assertOk();
        $ids = collect($reponse->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($produitA->id));
        $this->assertFalse($ids->contains($produitB->id));
    }

    public function test_liste_des_commandes_scopee_au_bon_fournisseur_et_filtrable_par_statut(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produitA = $this->creerProduitPhysique();
        $produitB = $this->creerProduitPhysique();

        $clientA = $this->creerClient();
        $adresseA = $this->creerAdresseAvecLocalite($clientA);
        $commandeA = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $clientA->id, 'adresse_id' => $adresseA->id,
            'lignes' => [['produit_id' => $produitA->id, 'quantite' => 1]],
        ]);
        Commande::findOrFail($commandeA->json('data.id'))->update(['statut_commande' => STATUT_COMMANDE_LIVREE]);

        $clientB = $this->creerClient();
        $adresseB = $this->creerAdresseAvecLocalite($clientB);
        $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $clientB->id, 'adresse_id' => $adresseB->id,
            'lignes' => [['produit_id' => $produitB->id, 'quantite' => 1]],
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$produitA->fournisseur_id}/commandes");
        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data.data'));

        $filtree = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$produitA->fournisseur_id}/commandes?statut=".STATUT_COMMANDE_EN_ATTENTE);
        $filtree->assertOk();
        $this->assertCount(0, $filtree->json('data.data'));
    }

    public function test_un_fournisseur_ne_peut_pas_consulter_ces_endpoints(): void
    {
        $produit = $this->creerProduitPhysique();
        $autreFournisseur = $this->creerFournisseur();

        $this->actingAs($autreFournisseur)->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}/produits")->assertForbidden();
        $this->actingAs($autreFournisseur)->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}/commandes")->assertForbidden();
    }
}
