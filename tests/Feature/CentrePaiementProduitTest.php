<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * "Centre de paiement des commissions" d'un produit (app Fournisseur) —
 * GET /produits/{produit}/centre-paiement.
 */
class CentrePaiementProduitTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function livrerUneCommande(\App\Models\Produit $produit): Commande
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commande = Commande::findOrFail($reponse->json('data.id'));

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();
        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/preparee")->assertOk();

        $livreurUser = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $livreurUser->assignRole(ROLE_LIVREUR);
        \App\Models\Livreur::create(['user_id' => $livreurUser->id]);
        $livraison = \App\Models\Livraison::where('commande_id', $commande->id)->firstOrFail();

        $this->actingAs($livreurUser)->postJson("/api/v1/livraisons/{$livraison->id}/affecter")->assertOk();
        $this->actingAs($livreurUser)->postJson("/api/v1/livraisons/{$livraison->id}/livrer", [
            'preuve_livraison' => UploadedFile::fake()->create('preuve.jpg', 100, 'image/jpeg'),
        ])->assertOk();

        return $commande->fresh();
    }

    public function test_le_fournisseur_voit_le_chiffre_d_affaires_de_son_produit_et_aucun_montant_a_payer_sans_achat_externe(): void
    {
        $produit = $this->creerProduitPhysique(['prix' => 100000]);
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $this->livrerUneCommande($produit);

        $reponse = $this->actingAs($fournisseur)->getJson("/api/v1/produits/{$produit->id}/centre-paiement?periode=tout");

        $reponse->assertOk();
        // Montant net crédité (prix - taux_commission par défaut du
        // fournisseur), pas le montant_total brut de la commande — voir
        // Commande::crediterFournisseursSiEligible().
        $montantNetAttendu = (float) $fournisseur->fournisseur->transactionsPortefeuille()->sole()->montant;
        $this->assertEquals($montantNetAttendu, $reponse->json('data.chiffre_affaires'));
        // Une commande in-app ne crée jamais de "reste à payer" : la marge
        // Ordi'Space est déjà retenue en amont, le fournisseur n'est que
        // crédité (voir Commande::crediterFournisseursSiEligible()).
        $this->assertEquals(0, $reponse->json('data.total_a_payer'));
        $this->assertSame(1, $reponse->json('data.compteurs.terminees'));
        $this->assertSame(0, $reponse->json('data.compteurs.nouvelle'));
        $this->assertCount(1, $reponse->json('data.transactions'));
        $this->assertSame('en_attente', $reponse->json('data.transactions.0.statut'));
        $this->assertCount(0, $reponse->json('data.achats_externes'));
    }

    public function test_le_total_a_payer_vient_des_achats_externes_declares_pas_des_ventes_in_app(): void
    {
        $produit = $this->creerProduitPhysique(['prix' => 50000]);
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $admin = $this->creerAdmin();
        $this->livrerUneCommande($produit);

        $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produit->id}/achats-externes", [
            'montant_vente' => 20000,
            'date_vente' => now()->toDateString(),
            'note' => 'Vente directe en boutique',
        ])->assertCreated();

        $tauxCommission = (float) $fournisseur->fournisseur->taux_commission;
        $commissionAttendue = round(20000 * $tauxCommission / 100, 2);

        $reponse = $this->actingAs($fournisseur)->getJson("/api/v1/produits/{$produit->id}/centre-paiement?periode=tout");
        $reponse->assertOk();
        $this->assertEquals($commissionAttendue, $reponse->json('data.total_a_payer'));
        $this->assertCount(1, $reponse->json('data.achats_externes'));
        $this->assertSame('en_attente', $reponse->json('data.achats_externes.0.statut'));

        // Un règlement groupé du flux in-app (Coordinateur/Admin) ne doit
        // jamais faire bouger ce total — concepts totalement découplés.
        $this->actingAs($admin)->postJson("/api/v1/fournisseurs/{$fournisseur->id}/portefeuille/payer-tout", [
            'reference_paiement' => 'REF-TEST-001',
        ])->assertCreated();

        $reponse = $this->actingAs($fournisseur)->getJson("/api/v1/produits/{$produit->id}/centre-paiement?periode=tout");
        $this->assertEquals($commissionAttendue, $reponse->json('data.total_a_payer'));
    }

    public function test_un_autre_fournisseur_ne_peut_pas_declarer_un_achat_externe(): void
    {
        $produit = $this->creerProduitPhysique();
        $autreFournisseur = $this->creerFournisseur();

        $this->actingAs($autreFournisseur)->postJson("/api/v1/produits/{$produit->id}/achats-externes", [
            'montant_vente' => 10000,
            'date_vente' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_le_chiffre_d_affaires_ne_compte_pas_les_ventes_d_un_autre_produit(): void
    {
        $produitA = $this->creerProduitPhysique(['prix' => 100000]);
        $produitB = $this->creerProduitPhysique(['prix' => 200000]);
        $fournisseurA = User::findOrFail($produitA->fournisseur_id);

        $this->livrerUneCommande($produitA);
        $this->livrerUneCommande($produitB);

        $reponse = $this->actingAs($fournisseurA)->getJson("/api/v1/produits/{$produitA->id}/centre-paiement?periode=tout");

        $reponse->assertOk();
        $montantNetAttendu = (float) $fournisseurA->fournisseur->transactionsPortefeuille()->sole()->montant;
        $this->assertEquals($montantNetAttendu, $reponse->json('data.chiffre_affaires'));
    }

    public function test_un_autre_fournisseur_ne_peut_pas_consulter_le_centre_de_paiement(): void
    {
        $produit = $this->creerProduitPhysique();
        $autreFournisseur = $this->creerFournisseur();

        $this->actingAs($autreFournisseur)->getJson("/api/v1/produits/{$produit->id}/centre-paiement")->assertForbidden();
    }

    public function test_le_detail_d_un_jour_liste_les_commandes_individuelles_avec_client_et_montant(): void
    {
        $produit = $this->creerProduitPhysique(['prix' => 100000]);
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $commande = $this->livrerUneCommande($produit);
        $transaction = $fournisseur->fournisseur->transactionsPortefeuille()->sole();

        $reponse = $this->actingAs($fournisseur)->getJson(
            "/api/v1/produits/{$produit->id}/centre-paiement/jour?date={$transaction->date_transaction->toDateString()}&statut=en_attente"
        );

        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data'));
        $this->assertSame($commande->id, $reponse->json('data.0.commande_id'));
        $this->assertEquals((float) $transaction->montant, $reponse->json('data.0.montant'));
        $this->assertNotNull($reponse->json('data.0.telephone'));
        $this->assertNotNull($reponse->json('data.0.zone_livraison'));
    }

    public function test_le_detail_d_un_jour_ne_montre_pas_les_commandes_d_un_autre_produit(): void
    {
        $produitA = $this->creerProduitPhysique(['prix' => 100000]);
        $produitB = $this->creerProduitPhysique(['prix' => 200000]);
        $fournisseurA = User::findOrFail($produitA->fournisseur_id);

        $this->livrerUneCommande($produitA);
        $this->livrerUneCommande($produitB);

        $aujourdHui = now()->toDateString();
        $reponse = $this->actingAs($fournisseurA)->getJson(
            "/api/v1/produits/{$produitA->id}/centre-paiement/jour?date={$aujourdHui}&statut=en_attente"
        );

        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data'));
    }

    public function test_un_autre_fournisseur_ne_peut_pas_consulter_le_detail_d_un_jour(): void
    {
        $produit = $this->creerProduitPhysique();
        $autreFournisseur = $this->creerFournisseur();

        $this->actingAs($autreFournisseur)
            ->getJson("/api/v1/produits/{$produit->id}/centre-paiement/jour?date=".now()->toDateString().'&statut=en_attente')
            ->assertForbidden();
    }

    public function test_le_fournisseur_peut_demander_une_reduction_de_commission_avec_un_motif(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);

        $achat = $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produit->id}/achats-externes", [
            'montant_vente' => 100000,
            'date_vente' => now()->toDateString(),
        ])->json('data');

        $reponse = $this->actingAs($fournisseur)->postJson(
            "/api/v1/produits/{$produit->id}/achats-externes/{$achat['id']}/demander-modification",
            ['montant_propose' => 5000, 'motif' => 'Produit repris partiellement par le client']
        );

        $reponse->assertOk();
        $this->assertEquals(5000, $reponse->json('data.montant_modifie_propose'));
        $this->assertSame('en_attente', $reponse->json('data.statut_modification'));

        // Tant qu'aucun Coordinateur n'a approuvé, le montant ORIGINAL reste
        // le seul dû — c'est la règle métier explicite du PDG.
        $centrePaiement = $this->actingAs($fournisseur)
            ->getJson("/api/v1/produits/{$produit->id}/centre-paiement?periode=tout")
            ->json('data');
        $this->assertEquals($achat['commission_due'], $centrePaiement['total_a_payer']);
        $this->assertEquals(5000, $centrePaiement['achats_externes'][0]['montant_modifie_propose']);
    }

    public function test_la_demande_de_modification_est_refusee_si_le_montant_n_est_pas_une_reduction(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);

        $achat = $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produit->id}/achats-externes", [
            'montant_vente' => 100000,
            'date_vente' => now()->toDateString(),
        ])->json('data');

        $this->actingAs($fournisseur)->postJson(
            "/api/v1/produits/{$produit->id}/achats-externes/{$achat['id']}/demander-modification",
            ['montant_propose' => $achat['commission_due'], 'motif' => 'Test']
        )->assertUnprocessable();
    }

    public function test_un_autre_fournisseur_ne_peut_pas_demander_de_modification(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $autreFournisseur = $this->creerFournisseur();

        $achat = $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produit->id}/achats-externes", [
            'montant_vente' => 100000,
            'date_vente' => now()->toDateString(),
        ])->json('data');

        $this->actingAs($autreFournisseur)->postJson(
            "/api/v1/produits/{$produit->id}/achats-externes/{$achat['id']}/demander-modification",
            ['montant_propose' => 5000, 'motif' => 'Test']
        )->assertForbidden();
    }
}
