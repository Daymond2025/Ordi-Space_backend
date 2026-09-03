<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class NegociationPrixTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(IMAGE_PRODUIT_DISQUE);
    }

    public function test_le_coordinateur_demarre_une_negociation_de_prix(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['prix' => 100000]);

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix", [
            'prix_propose' => 80000,
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('messages', [
            'produit_id' => $produit->id,
            'auteur_id' => $coordinateur->id,
            'type' => 'proposition_prix',
            'est_negociation_prix' => true,
        ]);
        $this->assertSame(100000, (int) $reponse->json('data.donnees.prix_liste'));
        $this->assertSame(80000, (int) $reponse->json('data.donnees.prix_propose'));
    }

    public function test_un_fournisseur_ne_peut_pas_demarrer_une_negociation(): void
    {
        $produit = $this->creerProduitPhysique();
        $proprietaire = User::findOrFail($produit->fournisseur_id);

        $this->actingAs($proprietaire)->postJson("/api/v1/produits/{$produit->id}/negociation-prix", [
            'prix_propose' => 80000,
        ])->assertForbidden();
    }

    public function test_le_fournisseur_peut_repondre_a_une_negociation_deja_demarree(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();
        $proprietaire = User::findOrFail($produit->fournisseur_id);

        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix", ['prix_propose' => 80000]);

        $this->actingAs($proprietaire)->postJson("/api/v1/produits/{$produit->id}/negociation-prix/messages", [
            'contenu' => 'Je peux descendre à 90 000.',
        ])->assertCreated();
    }

    public function test_le_fil_de_negociation_est_etanche_du_fil_de_discussion_general(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();

        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/messages", ['contenu' => 'Discussion normale.']);
        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix", ['prix_propose' => 80000]);

        $discussionGenerale = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/messages");
        $discussionGenerale->assertOk();
        $this->assertCount(1, $discussionGenerale->json('data.data'));
        $this->assertSame('texte', $discussionGenerale->json('data.data.0.type'));

        $negociation = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/negociation-prix");
        $negociation->assertOk();
        $this->assertCount(1, $negociation->json('data.data'));
        $this->assertSame('proposition_prix', $negociation->json('data.data.0.type'));
    }

    public function test_un_fichier_webm_avec_type_note_vocale_est_correctement_infere_en_audio(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();
        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix", ['prix_propose' => 80000]);

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix/messages", [
            'fichier' => UploadedFile::fake()->create('note.webm', 100, 'audio/webm'),
            'type' => 'note_vocale',
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('messages', [
            'produit_id' => $produit->id,
            'type' => 'note_vocale',
            'est_negociation_prix' => true,
        ]);
    }
}
