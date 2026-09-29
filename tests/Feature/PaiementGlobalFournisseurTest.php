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
 * Onglet "Paiement" (nav du bas, app Fournisseur) — GET /fournisseur/moi/paiements.
 * Vue globale fusionnant achats externes (tous produits) et crédits de
 * portefeuille (tous produits) — voir FournisseurController::moiPaiements().
 */
class PaiementGlobalFournisseurTest extends TestCase
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

    public function test_la_liste_fusionne_achats_externes_et_credits_portefeuille_de_tous_les_produits(): void
    {
        $produitA = $this->creerProduitPhysique(['prix' => 100000]);
        $produitB = $this->creerProduitPhysique(['prix' => 50000, 'fournisseur_id' => $produitA->fournisseur_id]);
        $fournisseur = User::findOrFail($produitA->fournisseur_id);

        // Crédit portefeuille (vente in-app) sur produit A.
        $this->livrerUneCommande($produitA);

        // Achat externe déclaré sur produit B.
        $achat = $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produitB->id}/achats-externes", [
            'montant_vente' => 20000,
            'date_vente' => now()->toDateString(),
        ])->json('data');

        $reponse = $this->actingAs($fournisseur)->getJson('/api/v1/fournisseur/moi/paiements');
        $reponse->assertOk();

        $items = $reponse->json('data.items');
        $this->assertCount(2, $items);

        $typesVus = collect($items)->pluck('type')->sort()->values()->all();
        $this->assertSame(['achat_externe', 'transaction'], $typesVus);

        $itemAchat = collect($items)->firstWhere('type', 'achat_externe');
        $this->assertSame($produitB->id, $itemAchat['produit_id']);
        $this->assertEquals($achat['commission_due'], $itemAchat['montant']);
        $this->assertSame('en_attente', $itemAchat['statut']);

        $itemTransaction = collect($items)->firstWhere('type', 'transaction');
        $this->assertSame('en_attente', $itemTransaction['statut']);
        $this->assertNotNull($itemTransaction['commande_id']);

        // Le total "à payer" (bouton "Payer tout") ne vient que des achats
        // externes en attente — jamais des crédits (concepts découplés, même
        // règle que ProduitController::centrePaiement()).
        $this->assertEquals($achat['commission_due'], $reponse->json('data.total_a_payer'));
        // Le crédit en_attente alimente "à_recevoir", jamais "à_payer"/"payé".
        $montantCredit = (float) $fournisseur->fournisseur->transactionsPortefeuille()->sole()->montant;
        $this->assertEquals($montantCredit, $reponse->json('data.total_a_recevoir'));
        $this->assertEquals(0, $reponse->json('data.total_paye'));
        $this->assertEquals((float) $fournisseur->fournisseur->solde_portefeuille, $reponse->json('data.solde'));
    }

    public function test_le_total_paye_cumule_achats_externes_et_credits_deja_regles(): void
    {
        $produit = $this->creerProduitPhysique(['prix' => 100000]);
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $admin = $this->creerAdmin();

        // Crédit in-app réglé (payé) via le flux Admin "Payer tout".
        $this->livrerUneCommande($produit);
        $montantCredit = (float) $fournisseur->fournisseur->transactionsPortefeuille()->sole()->montant;
        $this->actingAs($admin)->postJson("/api/v1/fournisseurs/{$fournisseur->id}/portefeuille/payer-tout", [
            'reference_paiement' => 'REF-TEST-002',
        ])->assertCreated();

        // Achat externe déjà réglé (statut basculé manuellement, aucun
        // endpoint de règlement réel n'existe encore côté back-office).
        $achat = $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produit->id}/achats-externes", [
            'montant_vente' => 20000,
            'date_vente' => now()->toDateString(),
        ])->json('data');
        \App\Models\AchatExterne::findOrFail($achat['id'])->update(['statut' => STATUT_ACHAT_EXTERNE_PAYE]);

        $reponse = $this->actingAs($fournisseur)->getJson('/api/v1/fournisseur/moi/paiements');
        $reponse->assertOk();
        $this->assertEquals($montantCredit + $achat['commission_due'], $reponse->json('data.total_paye'));
        $this->assertEquals(0, $reponse->json('data.total_a_payer'));
        $this->assertEquals(0, $reponse->json('data.total_a_recevoir'));
    }

    public function test_periode_aujourd_hui_exclut_un_achat_externe_declare_hier(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);

        $achat = $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produit->id}/achats-externes", [
            'montant_vente' => 20000,
            'date_vente' => now()->toDateString(),
        ])->json('data');
        // `created_at` n'est pas fillable (mass assignment protégé) — set
        // direct de l'attribut, comme pour tout timestamp qu'on veut piloter
        // en test.
        $achatModel = \App\Models\AchatExterne::findOrFail($achat['id']);
        $achatModel->created_at = now()->subDay();
        $achatModel->save();

        $reponse = $this->actingAs($fournisseur)->getJson('/api/v1/fournisseur/moi/paiements?periode=aujourd_hui');
        $reponse->assertOk();
        $this->assertCount(0, $reponse->json('data.items'));
        $this->assertEquals(0, $reponse->json('data.total_a_payer'));

        $reponse = $this->actingAs($fournisseur)->getJson('/api/v1/fournisseur/moi/paiements?periode=tout');
        $this->assertCount(1, $reponse->json('data.items'));
    }

    public function test_un_achat_externe_deja_paye_n_entre_pas_dans_le_total_a_payer(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);

        $achat = $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produit->id}/achats-externes", [
            'montant_vente' => 20000,
            'date_vente' => now()->toDateString(),
        ])->json('data');

        \App\Models\AchatExterne::findOrFail($achat['id'])->update(['statut' => STATUT_ACHAT_EXTERNE_PAYE]);

        $reponse = $this->actingAs($fournisseur)->getJson('/api/v1/fournisseur/moi/paiements');
        $reponse->assertOk();
        $this->assertEquals(0, $reponse->json('data.total_a_payer'));
        $this->assertSame('paye', $reponse->json('data.items.0.statut'));
    }

    public function test_un_fournisseur_ne_voit_jamais_les_paiements_d_un_autre(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $autreFournisseur = $this->creerFournisseur();

        $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produit->id}/achats-externes", [
            'montant_vente' => 20000,
            'date_vente' => now()->toDateString(),
        ])->assertCreated();

        $reponse = $this->actingAs($autreFournisseur)->getJson('/api/v1/fournisseur/moi/paiements');
        $reponse->assertOk();
        $this->assertCount(0, $reponse->json('data.items'));
        $this->assertEquals(0, $reponse->json('data.total_a_payer'));
    }

    public function test_un_autre_role_ne_peut_pas_consulter_cette_route_fournisseur(): void
    {
        $coordinateur = $this->creerCoordinateur();

        $this->actingAs($coordinateur)->getJson('/api/v1/fournisseur/moi/paiements')->assertForbidden();
    }
}
