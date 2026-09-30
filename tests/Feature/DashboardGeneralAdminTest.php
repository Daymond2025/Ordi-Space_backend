<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Produit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Dashboard général (page d'accueil Admin) — GET /admin/statistiques/tableau-de-bord,
 * voir StatistiqueController::tableauDeBordGeneral(). Vérifie surtout la file
 * "à traiter" (l'intérêt central de cet écran) et la réservation au rôle Admin.
 */
class DashboardGeneralAdminTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_le_dashboard_compte_les_produits_en_attente_de_validation(): void
    {
        $admin = $this->creerAdmin();
        $categorie = Categorie::first() ?? Categorie::create(['nom_categorie' => 'Test']);
        $fournisseurUser = $this->creerFournisseur();

        Produit::create([
            'categorie_id' => $categorie->id, 'nom_produit' => 'À valider', 'prix' => 100000,
            'quantite_stock' => 5, 'type_livraison' => 'physique', 'fournisseur_id' => $fournisseurUser->id,
            'statut_produit' => STATUT_PRODUIT_EN_ATTENTE, 'date_ajout' => now(),
        ]);

        $reponse = $this->actingAs($admin)->getJson('/api/v1/admin/statistiques/tableau-de-bord');

        $reponse->assertOk();
        $this->assertGreaterThanOrEqual(1, $reponse->json('data.a_traiter.produits_a_valider'));
        $this->assertArrayHasKey('utilisateurs', $reponse->json('data'));
        $this->assertArrayHasKey('commandes', $reponse->json('data'));
        $this->assertArrayHasKey('finance', $reponse->json('data'));
        $this->assertArrayHasKey('catalogue', $reponse->json('data'));
        $this->assertCount(7, $reponse->json('data.inscriptions_7j'));
    }

    public function test_seul_ladmin_consulte_le_dashboard_general(): void
    {
        $coordinateur = $this->creerCoordinateur();

        $this->actingAs($coordinateur)->getJson('/api/v1/admin/statistiques/tableau-de-bord')->assertForbidden();
    }
}
