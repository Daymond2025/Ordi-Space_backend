<?php

namespace Tests\Feature;

use App\Models\LigneCommande;
use App\Models\Produit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Prix négocié par l'Admin ("Enregistrer un achat" sur la fiche client,
 * FicheClient.tsx) — CommandeController::store(). Réservé à l'Admin, et
 * sans effet sur prix_partenaire_unitaire (la commission fournisseur reste
 * calculée sur le vrai prix du produit, jamais sur le prix négocié).
 */
class CommandePrixAdminTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerProduitAvecPrixVente(): Produit
    {
        // prix = partenaire (fournisseur), prix_vente = catalogue (client) —
        // snapshotté dans prix_partenaire_unitaire (voir CommandeController).
        return $this->creerProduitPhysique(['prix' => 400000, 'prix_vente' => 500000]);
    }

    public function test_un_admin_peut_negocier_le_prix_sans_affecter_la_commission_fournisseur(): void
    {
        $admin = $this->creerAdmin();
        $client = $this->creerClient();
        $produit = $this->creerProduitAvecPrixVente();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($admin)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire' => 420000]],
        ]);

        $reponse->assertCreated();
        $this->assertEquals(420000, $reponse->json('data.montant_total'));

        $ligne = LigneCommande::where('commande_id', $reponse->json('data.id'))->firstOrFail();
        $this->assertEquals(420000, $ligne->prix_unitaire);
        // Inchangé : toujours le vrai prix partenaire du produit (400000),
        // jamais le prix négocié — c'est ce qui sert de base à la commission
        // du fournisseur.
        $this->assertEquals(400000, $ligne->prix_partenaire_unitaire);
    }

    public function test_le_prix_negocie_est_ignore_pour_un_role_non_admin(): void
    {
        $commercial = $this->creerCommercial();
        $client = $this->creerClient();
        $produit = $this->creerProduitAvecPrixVente();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire' => 1]],
        ]);

        $reponse->assertCreated();
        // Le prix catalogue (prix_vente = 500000) prévaut, pas le "1" envoyé.
        $this->assertEquals(500000, $reponse->json('data.montant_total'));
    }

    public function test_sans_prix_unitaire_le_comportement_habituel_est_inchange(): void
    {
        $admin = $this->creerAdmin();
        $client = $this->creerClient();
        $produit = $this->creerProduitAvecPrixVente();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($admin)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        $reponse->assertCreated();
        $this->assertEquals(500000, $reponse->json('data.montant_total'));
    }
}
