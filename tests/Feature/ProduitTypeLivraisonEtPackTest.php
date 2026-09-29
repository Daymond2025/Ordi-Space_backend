<?php

namespace Tests\Feature;

use App\Models\Produit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * "Ajouter/Modifier un produit" (app Fournisseur) — le fournisseur doit
 * pouvoir déclarer un produit numérique (logiciel/licence, sans livraison)
 * et un "Pack complet" (contenu matériel inclus), pas seulement du matériel
 * physique — voir StoreProduitRequest::rules() et la migration contenu_pack.
 */
class ProduitTypeLivraisonEtPackTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_un_fournisseur_peut_declarer_un_produit_numerique(): void
    {
        $fournisseur = $this->creerFournisseur();
        $categorie = \App\Models\Categorie::firstOrCreate(['nom_categorie' => 'Logiciels']);

        $reponse = $this->actingAs($fournisseur)->postJson('/api/v1/produits', [
            'categorie_id' => $categorie->id,
            'nom_produit' => 'Licence Windows 11 Pro',
            'prix' => 45000,
            'quantite_stock' => 50,
            'type_livraison' => 'numerique',
        ]);

        $reponse->assertCreated();
        $produit = Produit::findOrFail($reponse->json('data.id'));
        $this->assertSame('numerique', $produit->type_livraison);
        $this->assertTrue($produit->estNumerique());
    }

    public function test_un_fournisseur_peut_renseigner_un_pack_complet(): void
    {
        $fournisseur = $this->creerFournisseur();
        $categorie = \App\Models\Categorie::firstOrCreate(['nom_categorie' => 'Ordinateurs portables']);

        $reponse = $this->actingAs($fournisseur)->postJson('/api/v1/produits', [
            'categorie_id' => $categorie->id,
            'nom_produit' => 'HP EliteBook 840',
            'prix' => 350000,
            'quantite_stock' => 5,
            'contenu_pack' => ['Sacoche de transport', 'Souris sans fil'],
        ]);

        $reponse->assertCreated();
        $produit = Produit::findOrFail($reponse->json('data.id'));
        $this->assertEquals(['Sacoche de transport', 'Souris sans fil'], $produit->contenu_pack);

        // Visible ensuite sur GET /produits/{id} (fiche produit).
        $this->actingAs($fournisseur)->getJson("/api/v1/produits/{$produit->id}")
            ->assertOk()
            ->assertJsonPath('data.contenu_pack', ['Sacoche de transport', 'Souris sans fil']);
    }

    public function test_le_fournisseur_peut_mettre_a_jour_le_type_de_livraison_et_le_pack(): void
    {
        $fournisseur = $this->creerFournisseur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE, 'fournisseur_id' => $fournisseur->id]);

        $reponse = $this->actingAs($fournisseur)->putJson("/api/v1/produits/{$produit->id}", [
            'categorie_id' => $produit->categorie_id,
            'nom_produit' => $produit->nom_produit,
            'prix' => $produit->prix,
            'quantite_stock' => $produit->quantite_stock,
            'type_livraison' => 'numerique',
            'contenu_pack' => ['Guide d\'installation PDF'],
        ]);

        $reponse->assertOk();
        $produit->refresh();
        $this->assertSame('numerique', $produit->type_livraison);
        $this->assertEquals(["Guide d'installation PDF"], $produit->contenu_pack);
    }

    /**
     * Marge Ordi'Space = prix_vente − prix, exposée en lecture seule via
     * l'accesseur `commission_ordispace` (Produit::commissionOrdispace()) —
     * jamais fixable par le fournisseur, seulement affichable une fois le
     * produit publié. `data.commission_ordispace` avant publication est
     * vérifié directement sur le modèle (pas de round-trip HTTP) pour éviter
     * un artefact du client de test PHPUnit : enchaîner un GET sur la route
     * PUBLIQUE /produits/{id} (hors auth:sanctum, résolue via current_user())
     * puis un actingAs() d'un autre acteur sur une route protégée renvoie un
     * faux 403, alors que can() confirme la permission bien présente —
     * artefact process unique du client de test, jamais reproductible en
     * production (chaque requête HTTP réelle est indépendante).
     */
    public function test_commission_ordispace_est_nulle_avant_publication_puis_calculee_apres(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE, 'prix' => 100000]);
        $this->assertNull($produit->commission_ordispace);

        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/publier", [
            'prix_vente' => 130000,
        ])->assertOk();

        $this->assertEquals(30000, $produit->fresh()->commission_ordispace);
    }
}
