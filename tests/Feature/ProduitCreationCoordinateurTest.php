<?php

namespace Tests\Feature;

use App\Models\Categorie;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class ProduitCreationCoordinateurTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_le_coordinateur_cree_un_produit_publie_directement_sans_fournisseur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $categorie = Categorie::firstOrCreate(['nom_categorie' => 'Ordinateurs portables']);

        $reponse = $this->actingAs($coordinateur)->postJson('/api/v1/produits', [
            'categorie_id' => $categorie->id,
            'nom_produit' => 'HP EliteBook 840',
            'prix' => 350000,
            'quantite_stock' => 5,
            'type_livraison' => TYPE_LIVRAISON_PHYSIQUE,
            'processeur' => 'Intel Core i5',
            'memoire_ram' => '16GB',
            'stockage' => '512GB SSD',
            'couleur' => 'Noir',
            'cadeaux' => ['Souris', 'Sac'],
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('produits', [
            'nom_produit' => 'HP EliteBook 840',
            'fournisseur_id' => null,
            'statut_produit' => STATUT_PRODUIT_VALIDE,
            'processeur' => 'Intel Core i5',
            'memoire_ram' => '16GB',
            'stockage' => '512GB SSD',
            'couleur' => 'Noir',
        ]);
        $this->assertSame(['Souris', 'Sac'], $reponse->json('data.cadeaux'));
    }

    public function test_un_fournisseur_cree_toujours_un_produit_en_attente_avec_son_propre_id(): void
    {
        $fournisseur = $this->creerFournisseur();
        $categorie = Categorie::firstOrCreate(['nom_categorie' => 'Ordinateurs portables']);

        $reponse = $this->actingAs($fournisseur)->postJson('/api/v1/produits', [
            'categorie_id' => $categorie->id,
            'nom_produit' => 'Laptop Fournisseur',
            'prix' => 200000,
            'quantite_stock' => 3,
            'type_livraison' => TYPE_LIVRAISON_PHYSIQUE,
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('produits', [
            'nom_produit' => 'Laptop Fournisseur',
            'fournisseur_id' => $fournisseur->id,
            'statut_produit' => STATUT_PRODUIT_EN_ATTENTE,
        ]);
    }
}
