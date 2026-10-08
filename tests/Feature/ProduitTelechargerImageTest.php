<?php

namespace Tests\Feature;

use App\Models\ImageProduit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * "Télécharger les images" (fiche produit, app Commercial) — voir
 * ProduitController::telechargerImage(). L'image est déjà publique via
 * /storage/**, cette route ne fait que forcer le téléchargement plutôt que
 * l'affichage inline, en passant par api/* pour bénéficier de CORS.
 */
class ProduitTelechargerImageTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake(IMAGE_PRODUIT_DISQUE);
    }

    private function creerImage(\App\Models\Produit $produit): ImageProduit
    {
        $chemin = 'produits/test-image.jpg';
        Storage::disk(IMAGE_PRODUIT_DISQUE)->put($chemin, 'contenu-image');

        return ImageProduit::create(['produit_id' => $produit->id, 'url_image' => $chemin, 'ordre_affichage' => 0]);
    }

    public function test_le_commercial_telecharge_une_image_dun_produit_publie(): void
    {
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();
        $image = $this->creerImage($produit);

        $reponse = $this->actingAs($commercial)->get("/api/v1/produits/{$produit->id}/images/{$image->id}/telecharger");

        $reponse->assertOk();
        $reponse->assertHeader('content-disposition');
        $this->assertStringContainsString('attachment', $reponse->headers->get('content-disposition'));
    }

    public function test_un_invite_sans_compte_peut_aussi_telecharger_limage_dun_produit_publie(): void
    {
        $produit = $this->creerProduitPhysique();
        $image = $this->creerImage($produit);

        $reponse = $this->get("/api/v1/produits/{$produit->id}/images/{$image->id}/telecharger");

        $reponse->assertOk();
    }

    public function test_404_si_limage_nappartient_pas_au_produit_de_lurl(): void
    {
        $commercial = $this->creerCommercial();
        $produitA = $this->creerProduitPhysique();
        $produitB = $this->creerProduitPhysique();
        $image = $this->creerImage($produitA);

        $reponse = $this->actingAs($commercial)->get("/api/v1/produits/{$produitB->id}/images/{$image->id}/telecharger");

        $reponse->assertNotFound();
    }

    public function test_404_pour_un_produit_non_publie_consulte_par_un_tiers(): void
    {
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE]);
        $image = $this->creerImage($produit);

        $reponse = $this->actingAs($commercial)->get("/api/v1/produits/{$produit->id}/images/{$image->id}/telecharger");

        $reponse->assertNotFound();
    }
}
