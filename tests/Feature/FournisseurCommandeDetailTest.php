<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Écran détail commande (app Fournisseur, onglets Suivi/Information) —
 * GET /commandes/{id} et /commandes/{id}/suivi, jusqu'ici réservés au
 * Coordinateur/Admin (PERMISSION_COMMANDES_CONSULTER). Le fournisseur y
 * accède désormais pour ses propres commandes via PERMISSION_MESSAGES_COMMANDE_GERER
 * + Commande::estAccessiblePar() (voir routes/api.php et CommandeController::show()).
 */
class FournisseurCommandeDetailTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommandePourProduit(\App\Models\Produit $produit): Commande
    {
        $commercial = $this->creerCommercial();
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        return Commande::findOrFail($reponse->json('data.id'));
    }

    public function test_le_fournisseur_voit_le_detail_de_sa_propre_commande_avec_apercu(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $commande = $this->creerCommandePourProduit($produit);

        $reponse = $this->actingAs($fournisseur)->getJson("/api/v1/commandes/{$commande->id}");

        $reponse->assertOk();
        $reponse->assertJsonPath('data.id', $commande->id);
        $this->assertNotNull($reponse->json('meta.apercu'));
        $this->assertSame($produit->nom_produit, $reponse->json('meta.apercu.nom_produit'));
    }

    public function test_le_fournisseur_voit_le_suivi_de_sa_propre_commande(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $commande = $this->creerCommandePourProduit($produit);

        $reponse = $this->actingAs($fournisseur)->getJson("/api/v1/commandes/{$commande->id}/suivi");

        $reponse->assertOk();
        $this->assertNotEmpty($reponse->json('data'));
    }

    public function test_un_fournisseur_ne_voit_pas_le_detail_de_la_commande_d_un_autre_fournisseur(): void
    {
        $produit = $this->creerProduitPhysique();
        $autreFournisseur = $this->creerFournisseur();
        $commande = $this->creerCommandePourProduit($produit);

        $this->actingAs($autreFournisseur)->getJson("/api/v1/commandes/{$commande->id}")->assertForbidden();
        $this->actingAs($autreFournisseur)->getJson("/api/v1/commandes/{$commande->id}/suivi")->assertForbidden();
    }

    public function test_le_fournisseur_peut_marquer_sa_commande_validee_preparee_depuis_cet_ecran(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommandePourProduit($produit);

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();

        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/preparee")->assertOk();

        $this->assertSame(STATUT_COMMANDE_EN_PREPARATION, $commande->fresh()->statut_commande);
    }
}
