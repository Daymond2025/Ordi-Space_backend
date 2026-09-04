<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Commande;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Models\TransactionPortefeuilleFournisseur;
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

    /**
     * Écran de publication : un produit avec prix_vente défini paie le
     * fournisseur sur l'intégralité de son prix partenaire, sans déduction
     * taux_commission — la marge Ordi'Space vient uniquement de l'écart
     * prix de vente/prix partenaire, pas d'une ponction sur le fournisseur.
     */
    public function test_le_fournisseur_touche_le_prix_partenaire_integral_pour_un_produit_publie(): void
    {
        $produit = $this->creerProduitPhysique(['prix' => 15000, 'prix_vente' => 18000, 'statut_produit' => STATUT_PRODUIT_VALIDE]);
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

        // Le client paie bien le prix de vente (18 000), pas le prix partenaire.
        $this->assertDatabaseHas('commandes', ['id' => $commandeId, 'montant_total' => 18000]);
        $this->assertDatabaseHas('lignes_commande', [
            'commande_id' => $commandeId, 'prix_unitaire' => 18000, 'prix_partenaire_unitaire' => 15000,
        ]);

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commandeId}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        // Prix partenaire intégral (15 000), aucune déduction malgré taux_commission=20%.
        $this->assertDatabaseHas('transactions_portefeuille_fournisseurs', [
            'fournisseur_id' => $fournisseur->user_id, 'type' => 'credit', 'montant' => 15000,
            'commission_prelevee' => 0, 'commande_id' => $commandeId,
        ]);
        $this->assertEquals(15000, $fournisseur->fresh()->solde_portefeuille);
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

    /**
     * `transactions` (écran Portefeuille fournisseur) ne liste que les
     * crédits (ventes) — un débit seul (paiement manuel) n'y apparaît pas,
     * mais impacte bien `solde`.
     */
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
        $this->assertCount(0, $reponse->json('data.transactions.data'));
    }

    public function test_une_vente_livree_cree_un_credit_en_attente_avec_produit_et_photo(): void
    {
        $produit = $this->creerProduitPhysique(['prix' => 20000]);
        $fournisseur = Fournisseur::findOrFail($produit->fournisseur_id);
        $fournisseur->update(['taux_commission' => 0]);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);
        $admin = $this->creerAdmin();
        $coordinateur = $this->creerCoordinateur();

        $creation = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commandeId = $creation->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commandeId}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        $this->assertDatabaseHas('transactions_portefeuille_fournisseurs', [
            'fournisseur_id' => $fournisseur->user_id, 'type' => 'credit', 'statut' => STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE,
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$fournisseur->user_id}/portefeuille");
        $reponse->assertOk();
        $reponse->assertJsonPath('data.total_en_attente', 20000);
        $ligne = $reponse->json('data.transactions.data')[0];
        $this->assertSame('en_attente', $ligne['statut']);
        $this->assertSame($produit->nom_produit, $ligne['nom_produit']);
    }

    public function test_payer_tout_bascule_les_credits_en_attente_et_cree_un_debit_global(): void
    {
        $produitA = $this->creerProduitPhysique(['prix' => 20000]);
        $fournisseur = Fournisseur::findOrFail($produitA->fournisseur_id);
        $fournisseur->update(['taux_commission' => 0]);
        $produitB = $this->creerProduitPhysique(['fournisseur_id' => $fournisseur->user_id, 'prix' => 15000]);
        $admin = $this->creerAdmin();
        $coordinateur = $this->creerCoordinateur();

        foreach ([$produitA, $produitB] as $produit) {
            $client = $this->creerClient();
            $adresse = $this->creerAdresseAvecLocalite($client);
            $creation = $this->actingAs($client)->postJson('/api/v1/commandes', [
                'adresse_id' => $adresse->id,
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            ]);
            $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$creation->json('data.id')}/statut", [
                'statut_commande' => 'livree',
            ])->assertOk();
        }

        $this->assertEquals(35000, $fournisseur->fresh()->solde_portefeuille);

        $this->actingAs($coordinateur)->postJson("/api/v1/fournisseurs/{$fournisseur->user_id}/portefeuille/payer-tout", [
            'reference_paiement' => 'WAVE-2026-0912',
        ])->assertCreated();

        $this->assertEquals(0, $fournisseur->fresh()->solde_portefeuille);
        $this->assertDatabaseHas('transactions_portefeuille_fournisseurs', [
            'fournisseur_id' => $fournisseur->user_id, 'type' => 'debit', 'montant' => 35000,
        ]);
        $this->assertDatabaseCount('transactions_portefeuille_fournisseurs', 3);
        $this->assertDatabaseMissing('transactions_portefeuille_fournisseurs', [
            'fournisseur_id' => $fournisseur->user_id, 'type' => 'credit', 'statut' => STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE,
        ]);
        // La référence saisie par le coordinateur est copiée sur les deux crédits réglés.
        $this->assertSame(2, TransactionPortefeuilleFournisseur::where([
            'fournisseur_id' => $fournisseur->user_id, 'type' => 'credit', 'reference_paiement' => 'WAVE-2026-0912',
        ])->count());
    }

    public function test_payer_tout_exige_une_reference_de_paiement(): void
    {
        $produit = $this->creerProduitPhysique(['prix' => 10000]);
        $fournisseur = Fournisseur::findOrFail($produit->fournisseur_id);
        $fournisseur->update(['taux_commission' => 0]);
        $admin = $this->creerAdmin();
        $coordinateur = $this->creerCoordinateur();

        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);
        $creation = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$creation->json('data.id')}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/fournisseurs/{$fournisseur->user_id}/portefeuille/payer-tout");

        $reponse->assertStatus(422);
        $this->assertArrayHasKey('reference_paiement', $reponse->json('error.fields'));
    }

    public function test_payer_tout_echoue_si_rien_n_est_en_attente(): void
    {
        $fournisseurUser = $this->creerFournisseur();
        $coordinateur = $this->creerCoordinateur();

        $this->actingAs($coordinateur)->postJson("/api/v1/fournisseurs/{$fournisseurUser->id}/portefeuille/payer-tout", [
            'reference_paiement' => 'WAVE-2026-0912',
        ])->assertStatus(422);
    }

    public function test_un_fournisseur_ou_commercial_ne_peut_pas_payer_tout(): void
    {
        $fournisseurUser = $this->creerFournisseur();
        $commercial = $this->creerCommercial();

        $this->actingAs($fournisseurUser)->postJson("/api/v1/fournisseurs/{$fournisseurUser->id}/portefeuille/payer-tout")
            ->assertForbidden();

        $this->actingAs($commercial)->postJson("/api/v1/fournisseurs/{$fournisseurUser->id}/portefeuille/payer-tout")
            ->assertForbidden();
    }
}
