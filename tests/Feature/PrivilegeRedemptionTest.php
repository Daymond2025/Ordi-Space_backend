<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Client;
use App\Models\FraisLivraisonProduit;
use App\Models\Localite;
use App\Models\Privilege;
use App\Models\Produit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class PrivilegeRedemptionTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerAdresse($clientUser)
    {
        return $this->creerAdresseAvecLocalite($clientUser);
    }

    private function commanderProduit($client, $produit, array $extra = [])
    {
        $adresse = $this->creerAdresse($client);

        return $this->actingAs($client)->postJson('/api/v1/commandes', array_merge([
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ], $extra));
    }

    public function test_un_code_remise_pourcentage_reduit_le_montant_correctement(): void
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique(['prix' => 100000]);
        Privilege::create([
            'titre' => 'Remise 15%', 'type_privilege' => 'remise_pourcentage', 'valeur' => 15,
            'code_promo' => 'PROMO15', 'actif' => true,
        ]);

        $reponse = $this->commanderProduit($client, $produit, ['code_promo' => 'PROMO15']);

        $reponse->assertCreated();
        $this->assertDatabaseHas('commandes', [
            'client_id' => $client->id, 'montant_total' => 100000, 'montant_remise' => 15000,
        ]);
    }

    public function test_un_code_remise_montant_est_plafonne_au_total_de_la_commande(): void
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique(['prix' => 5000]);
        Privilege::create([
            'titre' => 'Cashback', 'type_privilege' => 'remise_montant', 'valeur' => 8000,
            'code_promo' => 'CASHBACK8000', 'actif' => true,
        ]);

        $reponse = $this->commanderProduit($client, $produit, ['code_promo' => 'CASHBACK8000']);
        $reponse->assertCreated();
        // La remise ne peut jamais dépasser le montant de la commande (5000, pas 8000).
        $this->assertDatabaseHas('commandes', ['montant_remise' => 5000]);
    }

    public function test_un_code_promo_ne_peut_pas_depasser_sa_limite_d_utilisation(): void
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique(['prix' => 10000, 'quantite_stock' => 10]);
        Privilege::create([
            'titre' => 'Une fois', 'type_privilege' => 'remise_pourcentage', 'valeur' => 10,
            'code_promo' => 'UNEFOIS', 'actif' => true, 'limite_utilisation_par_client' => 1,
        ]);

        $this->commanderProduit($client, $produit, ['code_promo' => 'UNEFOIS'])->assertCreated();
        $this->commanderProduit($client, $produit, ['code_promo' => 'UNEFOIS'])->assertUnprocessable();
    }

    public function test_un_code_promo_invalide_ou_expire_est_rejete(): void
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();
        Privilege::create([
            'titre' => 'Expiré', 'type_privilege' => 'remise_pourcentage', 'valeur' => 10,
            'code_promo' => 'EXPIRE', 'actif' => true, 'date_fin' => now()->subDay(),
        ]);

        $this->commanderProduit($client, $produit, ['code_promo' => 'EXPIRE'])->assertUnprocessable();
        $this->commanderProduit($client, $produit, ['code_promo' => 'NIMPORTEQUOI'])->assertUnprocessable();
    }

    public function test_le_parrain_est_credite_quand_la_commande_du_filleul_est_livree(): void
    {
        $parrain = $this->creerClient();
        $filleul = $this->creerClient();
        $admin = $this->creerAdmin();
        $produit = $this->creerProduitPhysique(['prix' => 200000]);
        Privilege::create([
            'titre' => 'Carte invitation', 'type_privilege' => 'parrainage', 'valeur' => 5000, 'actif' => true,
        ]);

        $codeParrain = Client::where('user_id', $parrain->id)->value('code_parrainage');
        $commande = $this->commanderProduit($filleul, $produit, ['code_parrainage' => $codeParrain]);
        $commande->assertCreated();
        $commandeId = $commande->json('data.id');

        // Pas encore livrée : aucun crédit ne doit avoir été versé à ce stade.
        $this->assertDatabaseMissing('transactions_portefeuille', ['client_id' => $parrain->id]);

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commandeId}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        $this->assertDatabaseHas('transactions_portefeuille', [
            'client_id' => $parrain->id, 'montant' => 5000,
        ]);
        $this->assertEquals(5000, Client::where('user_id', $parrain->id)->value('solde_portefeuille'));
    }

    /**
     * Régression : un ordinateur publié directement par l'Admin (sans
     * fournisseur) doit aussi déclencher la récompense de parrainage —
     * seul le type de livraison (physique vs numérique) doit compter.
     */
    public function test_le_parrainage_fonctionne_aussi_sur_un_produit_publie_directement_par_l_admin(): void
    {
        $parrain = $this->creerClient();
        $filleul = $this->creerClient();
        $admin = $this->creerAdmin();

        $categorie = Categorie::firstOrCreate(['nom_categorie' => 'Ordinateurs portables']);
        $produitAdmin = Produit::create([
            'fournisseur_id' => null,
            'categorie_id' => $categorie->id,
            'nom_produit' => 'Laptop publié par Admin',
            'prix' => 150000,
            'quantite_stock' => 5,
            'statut_produit' => STATUT_PRODUIT_VALIDE,
            'type_livraison' => 'physique',
        ]);

        FraisLivraisonProduit::create([
            'produit_id' => $produitAdmin->id,
            'localite_id' => Localite::where('nom', 'Cocody')->value('id'),
            'montant' => 2000,
        ]);

        Privilege::create([
            'titre' => 'Carte invitation', 'type_privilege' => 'parrainage', 'valeur' => 5000, 'actif' => true,
        ]);

        $codeParrain = Client::where('user_id', $parrain->id)->value('code_parrainage');
        $commande = $this->commanderProduit($filleul, $produitAdmin, ['code_parrainage' => $codeParrain]);
        $commandeId = $commande->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commandeId}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        $this->assertDatabaseHas('transactions_portefeuille', [
            'client_id' => $parrain->id, 'montant' => 5000,
        ]);
    }

    public function test_le_parrainage_ne_se_declenche_pas_sur_une_licence_numerique(): void
    {
        $parrain = $this->creerClient();
        $filleul = $this->creerClient();
        $admin = $this->creerAdmin();
        $produitNumerique = $this->creerProduitPhysique(['nom_produit' => 'Licence', 'type_livraison' => 'numerique', 'prix' => 20000]);

        Privilege::create([
            'titre' => 'Carte invitation', 'type_privilege' => 'parrainage', 'valeur' => 5000, 'actif' => true,
        ]);

        $codeParrain = Client::where('user_id', $parrain->id)->value('code_parrainage');

        // Pas d'adresse nécessaire pour un produit 100% numérique.
        $commande = $this->actingAs($filleul)->postJson('/api/v1/commandes', [
            'code_parrainage' => $codeParrain,
            'lignes' => [['produit_id' => $produitNumerique->id, 'quantite' => 1]],
        ]);
        $commande->assertCreated();
        $commandeId = $commande->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commandeId}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        $this->assertDatabaseMissing('transactions_portefeuille', ['client_id' => $parrain->id]);
    }

    public function test_un_client_ne_peut_pas_utiliser_son_propre_code_de_parrainage(): void
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();
        $sonPropreCode = Client::where('user_id', $client->id)->value('code_parrainage');

        $this->commanderProduit($client, $produit, ['code_parrainage' => $sonPropreCode])->assertUnprocessable();
    }
}
