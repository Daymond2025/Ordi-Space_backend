<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class MessageProduitTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(IMAGE_PRODUIT_DISQUE);
    }

    public function test_le_coordinateur_peut_poster_un_message_texte(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/messages", [
            'contenu' => 'Bonjour, du stock arrive lundi.',
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('messages', [
            'produit_id' => $produit->id, 'auteur_id' => $coordinateur->id, 'type' => 'texte',
        ]);
    }

    public function test_un_commercial_peut_poster(): void
    {
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();

        $this->actingAs($commercial)->postJson("/api/v1/produits/{$produit->id}/messages", [
            'contenu' => 'Client intéressé.',
        ])->assertCreated();
    }

    public function test_le_fournisseur_proprietaire_peut_poster_mais_pas_un_autre(): void
    {
        $produit = $this->creerProduitPhysique();
        $proprietaire = User::findOrFail($produit->fournisseur_id);
        $autreFournisseur = User::factory()->create(['type_utilisateur' => ROLE_FOURNISSEUR]);
        $autreFournisseur->assignRole(ROLE_FOURNISSEUR);

        $this->actingAs($proprietaire)->postJson("/api/v1/produits/{$produit->id}/messages", [
            'contenu' => 'Stock disponible.',
        ])->assertCreated();

        $this->actingAs($autreFournisseur)->postJson("/api/v1/produits/{$produit->id}/messages", [
            'contenu' => 'Je ne devrais pas pouvoir écrire ici.',
        ])->assertForbidden();
    }

    public function test_client_et_livreur_sont_exclus_de_la_discussion_produit(): void
    {
        $produit = $this->creerProduitPhysique();
        $client = $this->creerClient();
        $livreur = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $livreur->assignRole(ROLE_LIVREUR);

        $this->actingAs($client)->postJson("/api/v1/produits/{$produit->id}/messages", ['contenu' => 'x'])->assertForbidden();
        $this->actingAs($livreur)->postJson("/api/v1/produits/{$produit->id}/messages", ['contenu' => 'x'])->assertForbidden();
    }

    public function test_upload_image_infere_le_bon_type(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/messages", [
            'fichier' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('messages', ['produit_id' => $produit->id, 'type' => 'image']);
    }

    public function test_video_trop_lourde_est_rejetee(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/messages", [
            'fichier' => UploadedFile::fake()->create('clip.mp4', VIDEO_MAX_POIDS_KO + 1000, 'video/mp4'),
        ]);

        $reponse->assertUnprocessable();
    }

    public function test_document_trop_lourd_est_rejete(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/messages", [
            'fichier' => UploadedFile::fake()->create('facture.pdf', DOCUMENT_MAX_POIDS_KO + 1000, 'application/pdf'),
        ]);

        $reponse->assertUnprocessable();
    }

    public function test_contenu_et_fichier_ensemble_est_rejete(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();

        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/messages", [
            'contenu' => 'texte', 'fichier' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
        ])->assertUnprocessable();
    }

    public function test_pagination_de_l_index(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();

        foreach (range(1, 3) as $i) {
            $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/messages", ['contenu' => "Message {$i}"]);
        }

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/messages");

        $reponse->assertOk();
        $this->assertCount(3, $reponse->json('data.data'));
    }
}
