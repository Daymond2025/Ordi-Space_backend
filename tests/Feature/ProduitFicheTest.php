<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\ImageProduit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class ProduitFicheTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake(IMAGE_PRODUIT_DISQUE);
    }

    public function test_le_coordinateur_corrige_la_fiche_sans_toucher_au_prix_ni_au_stock(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $autreCategorie = Categorie::create(['nom_categorie' => 'Accessoires bureautique']);
        $produit = $this->creerProduitPhysique(['prix' => 100000, 'quantite_stock' => 10]);

        $reponse = $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/fiche", [
            'nom_produit' => 'Laptop Corrigé',
            'categorie_id' => $autreCategorie->id,
            'processeur' => 'Intel Core i5',
            'cadeaux' => ['Souris', 'Sac'],
        ]);

        $reponse->assertOk();
        $produit->refresh();
        $this->assertSame('Laptop Corrigé', $produit->nom_produit);
        $this->assertSame($autreCategorie->id, $produit->categorie_id);
        $this->assertSame('Intel Core i5', $produit->processeur);
        $this->assertSame(['Souris', 'Sac'], $produit->cadeaux);
        $this->assertEquals(100000, $produit->prix, 'le prix ne doit jamais être modifié par cet écran');
        $this->assertSame(10, $produit->quantite_stock, 'le stock ne doit jamais être modifié par cet écran');
    }

    public function test_un_fournisseur_ne_peut_pas_appeler_modifier_fiche(): void
    {
        $produit = $this->creerProduitPhysique();
        $proprietaire = User::findOrFail($produit->fournisseur_id);

        $this->actingAs($proprietaire)->patchJson("/api/v1/produits/{$produit->id}/fiche", [
            'nom_produit' => 'Tentative',
        ])->assertForbidden();
    }

    public function test_le_coordinateur_ajoute_et_supprime_une_image_sur_un_produit_qu_il_ne_possede_pas(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();

        $ajout = $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/images", [
            'images' => [UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg')],
        ]);
        $ajout->assertCreated();
        $image = ImageProduit::where('produit_id', $produit->id)->firstOrFail();

        $this->actingAs($coordinateur)
            ->deleteJson("/api/v1/produits/{$produit->id}/images/{$image->id}")
            ->assertOk();
        $this->assertDatabaseMissing('images_produits', ['id' => $image->id]);
    }

    public function test_le_fournisseur_proprietaire_gere_toujours_ses_propres_images(): void
    {
        // ProduitPolicy::update() n'autorise le fournisseur que sur son
        // produit en_attente/rejete/corrige, jamais déjà valide.
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE]);
        $proprietaire = User::findOrFail($produit->fournisseur_id);

        $this->actingAs($proprietaire)->postJson("/api/v1/produits/{$produit->id}/images", [
            'images' => [UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg')],
        ])->assertCreated();
    }

    public function test_un_autre_fournisseur_ne_peut_pas_ajouter_d_image(): void
    {
        $produit = $this->creerProduitPhysique();
        $autreFournisseur = $this->creerFournisseur();

        $this->actingAs($autreFournisseur)->postJson("/api/v1/produits/{$produit->id}/images", [
            'images' => [UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg')],
        ])->assertForbidden();
    }
}
