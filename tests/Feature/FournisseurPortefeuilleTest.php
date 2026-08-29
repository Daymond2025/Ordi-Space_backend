<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Commande;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class FournisseurPortefeuilleTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_le_fournisseur_est_credite_a_la_livraison_selon_son_taux(): void
    {
        $produit = $this->creerProduitPhysique(['prix' => 100000]);
        $fournisseur = Fournisseur::findOrFail($produit->fournisseur_id);
        $fournisseur->update(['taux_commission' => 20]);

        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);
        $admin = $this->creerAdmin();

        $creation = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commandeId = $creation->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commandeId}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        // 100 000 - 20% de commission = 80 000 net, 20 000 de commission prélevée.
        $this->assertDatabaseHas('transactions_portefeuille_fournisseurs', [
            'fournisseur_id' => $fournisseur->user_id, 'type' => 'credit', 'montant' => 80000,
            'commission_prelevee' => 20000, 'commande_id' => $commandeId,
        ]);
        $this->assertEquals(80000, $fournisseur->fresh()->solde_portefeuille);
    }

    public function test_un_produit_publie_par_l_admin_n_a_aucune_commission(): void
    {
        $categorie = Categorie::firstOrCreate(['nom_categorie' => 'Accessoires']);
        $produitAdmin = Produit::create([
            'fournisseur_id' => null,
            'categorie_id' => $categorie->id,
            'nom_produit' => 'Souris',
            'prix' => 10000,
            'quantite_stock' => 5,
            'statut_produit' => STATUT_PRODUIT_VALIDE,
            'type_livraison' => 'numerique',
        ]);

        $client = $this->creerClient();
        $admin = $this->creerAdmin();

        $creation = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'lignes' => [['produit_id' => $produitAdmin->id, 'quantite' => 1]],
        ]);
        $commandeId = $creation->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commandeId}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        $this->assertDatabaseCount('transactions_portefeuille_fournisseurs', 0);
    }

    public function test_la_commission_n_est_versee_qu_une_seule_fois(): void
    {
        $produit = $this->creerProduitPhysique(['prix' => 100000]);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);
        $admin = $this->creerAdmin();

        $creation = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commande = Commande::findOrFail($creation->json('data.id'));

        $commande->crediterFournisseursSiEligible();
        $commande->crediterFournisseursSiEligible();

        $this->assertDatabaseCount('transactions_portefeuille_fournisseurs', 1);
    }

    public function test_admin_et_coordinateur_peuvent_enregistrer_un_paiement(): void
    {
        $fournisseurUser = $this->creerFournisseur();
        $fournisseur = Fournisseur::findOrFail($fournisseurUser->id);
        $fournisseur->update(['solde_portefeuille' => 50000]);
        $admin = $this->creerAdmin();
        $coordinateur = $this->creerCoordinateur();

        $this->actingAs($admin)->postJson("/api/v1/fournisseurs/{$fournisseurUser->id}/portefeuille/paiement", [
            'montant' => 20000, 'motif' => 'Virement mensuel',
        ])->assertCreated();

        $this->assertEquals(30000, $fournisseur->fresh()->solde_portefeuille);
        $this->assertDatabaseHas('transactions_portefeuille_fournisseurs', [
            'fournisseur_id' => $fournisseurUser->id, 'type' => 'debit', 'montant' => 20000, 'acteur_id' => $admin->id,
        ]);

        $this->actingAs($coordinateur)->postJson("/api/v1/fournisseurs/{$fournisseurUser->id}/portefeuille/paiement", [
            'montant' => 10000,
        ])->assertCreated();

        $this->assertEquals(20000, $fournisseur->fresh()->solde_portefeuille);
        $this->assertDatabaseHas('transactions_portefeuille_fournisseurs', [
            'fournisseur_id' => $fournisseurUser->id, 'type' => 'debit', 'montant' => 10000, 'acteur_id' => $coordinateur->id,
        ]);
    }

    public function test_un_fournisseur_ou_commercial_ne_peut_pas_enregistrer_un_paiement(): void
    {
        $fournisseurUser = $this->creerFournisseur();
        $commercial = $this->creerCommercial();

        $this->actingAs($fournisseurUser)->postJson("/api/v1/fournisseurs/{$fournisseurUser->id}/portefeuille/paiement", [
            'montant' => 1000,
        ])->assertForbidden();

        $this->actingAs($commercial)->postJson("/api/v1/fournisseurs/{$fournisseurUser->id}/portefeuille/paiement", [
            'montant' => 1000,
        ])->assertForbidden();
    }

    public function test_seul_l_admin_peut_modifier_le_taux_de_commission(): void
    {
        $fournisseurUser = $this->creerFournisseur();
        $admin = $this->creerAdmin();
        $coordinateur = $this->creerCoordinateur();

        $this->actingAs($coordinateur)->patchJson("/api/v1/fournisseurs/{$fournisseurUser->id}/commission", [
            'taux_commission' => 15,
        ])->assertForbidden();

        $this->actingAs($admin)->patchJson("/api/v1/fournisseurs/{$fournisseurUser->id}/commission", [
            'taux_commission' => 15,
        ])->assertOk();

        $this->assertDatabaseHas('fournisseurs', ['user_id' => $fournisseurUser->id, 'taux_commission' => 15]);
    }

    public function test_le_coordinateur_consulte_le_portefeuille_et_l_historique(): void
    {
        $fournisseurUser = $this->creerFournisseur();
        $admin = $this->creerAdmin();
        $coordinateur = $this->creerCoordinateur();

        $this->actingAs($admin)->postJson("/api/v1/fournisseurs/{$fournisseurUser->id}/portefeuille/paiement", [
            'montant' => 5000,
        ])->assertCreated();

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$fournisseurUser->id}/portefeuille");

        $reponse->assertOk();
        $this->assertEquals(-5000, $reponse->json('data.solde'));
        $this->assertCount(1, $reponse->json('data.transactions.data'));
    }
}
