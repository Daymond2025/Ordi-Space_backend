<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Fournisseur;
use App\Models\TransactionPortefeuilleFournisseur;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class FournisseurStatistiquesTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerEtLivrerCommande($produit, $admin): int
    {
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $creation = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commandeId = $creation->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commandeId}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        return $commandeId;
    }

    /**
     * commission_ordispace combine commission_prelevee (ancien flux,
     * taux_commission) et (prix_unitaire - prix_partenaire_unitaire)
     * (nouveau flux, marge négociée) — les deux flux coexistent chez un même
     * fournisseur.
     */
    public function test_commission_ordispace_combine_ancien_et_nouveau_flux(): void
    {
        $produitAncienFlux = $this->creerProduitPhysique(['prix' => 100000]);
        $fournisseur = Fournisseur::findOrFail($produitAncienFlux->fournisseur_id);
        $fournisseur->update(['taux_commission' => 20]);

        $produitNouveauFlux = $this->creerProduitPhysique([
            'fournisseur_id' => $fournisseur->user_id, 'prix' => 15000, 'prix_vente' => 18000, 'statut_produit' => STATUT_PRODUIT_VALIDE,
        ]);

        $admin = $this->creerAdmin();
        $coordinateur = $this->creerCoordinateur();

        $this->creerEtLivrerCommande($produitAncienFlux, $admin);
        $this->creerEtLivrerCommande($produitNouveauFlux, $admin);

        // Ancien flux : 100 000 * 20% = 20 000 de commission_prelevee.
        // Nouveau flux : 18 000 (prix_vente payé) - 15 000 (prix_partenaire) = 3 000.
        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$fournisseur->user_id}/statistiques");

        $reponse->assertOk();
        $reponse->assertJsonPath('data.commission_ordispace', 23000);
        $reponse->assertJsonPath('data.chiffre_affaires', 95000);
        $reponse->assertJsonPath('data.commandes_livrees', 2);
        $reponse->assertJsonPath('data.produits_distincts_vendus', 2);
    }

    public function test_produits_plus_vendus_expose_photo_et_montant(): void
    {
        $produit = $this->creerProduitPhysique(['prix' => 50000]);
        $fournisseur = Fournisseur::findOrFail($produit->fournisseur_id);
        $fournisseur->update(['taux_commission' => 0]);
        $admin = $this->creerAdmin();
        $coordinateur = $this->creerCoordinateur();

        $this->creerEtLivrerCommande($produit, $admin);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$fournisseur->user_id}/statistiques");

        $reponse->assertOk();
        $ligne = $reponse->json('data.produits_plus_vendus')[0];
        $this->assertSame($produit->nom_produit, $ligne['nom_produit']);
        $this->assertSame(1, $ligne['quantite_vendue']);
        $this->assertEquals(50000, $ligne['montant']);
    }

    public function test_croissance_pourcentage_compare_a_la_periode_precedente(): void
    {
        $fournisseurUser = $this->creerFournisseur();
        $fournisseur = Fournisseur::findOrFail($fournisseurUser->id);
        $coordinateur = $this->creerCoordinateur();

        // Semaine dernière : 10 000. Cette semaine : 15 000 → +50%.
        TransactionPortefeuilleFournisseur::create([
            'fournisseur_id' => $fournisseur->user_id, 'type' => 'credit', 'statut' => 'paye',
            'montant' => 10000, 'motif' => 'Test', 'solde_apres' => 10000,
            'date_transaction' => now()->subWeek()->startOfWeek()->addDay(),
        ]);
        TransactionPortefeuilleFournisseur::create([
            'fournisseur_id' => $fournisseur->user_id, 'type' => 'credit', 'statut' => 'paye',
            'montant' => 15000, 'motif' => 'Test', 'solde_apres' => 25000,
            'date_transaction' => now()->startOfWeek()->addDay(),
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$fournisseur->user_id}/statistiques?periode=semaine");

        $reponse->assertOk();
        $reponse->assertJsonPath('data.chiffre_affaires', 15000);
        $reponse->assertJsonPath('data.croissance_pourcentage', 50);
    }

    public function test_periode_tout_ne_calcule_pas_de_croissance(): void
    {
        $fournisseurUser = $this->creerFournisseur();
        $coordinateur = $this->creerCoordinateur();

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$fournisseurUser->id}/statistiques");

        $reponse->assertOk();
        $reponse->assertJsonPath('data.croissance_pourcentage', null);
    }
}
