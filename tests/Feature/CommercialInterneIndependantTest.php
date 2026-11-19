<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Commercial;
use App\Models\Produit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Commercial interne (1 000 F par produit vendu) / indépendant (commission
 * du produit, 2 500 F par défaut) — compte créé par l'Admin avec son numéro,
 * connexion par téléphone seulement, déconnexion par l'Admin.
 */
class CommercialInterneIndependantTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function commercial(string $categorie): User
    {
        $user = $this->creerCommercial();
        Commercial::where('user_id', $user->id)->update(['categorie_commercial' => $categorie]);

        return $user;
    }

    private function venteValidee(User $commercial, Produit $produit, int $quantite): Commande
    {
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => $quantite]],
        ])->assertCreated();

        $commande = Commande::findOrFail($reponse->json('data.id'));
        $this->actingAs($this->creerCoordinateur())->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();

        return $commande->fresh();
    }

    public function test_un_commercial_interne_touche_1000_par_produit_vendu(): void
    {
        $commercial = $this->commercial(CATEGORIE_COMMERCIAL_INTERNE);
        // La commission indépendant du produit ne le concerne pas.
        $produit = $this->creerProduitPhysique(['quantite_stock' => 10, 'commission_commercial_independant' => 5000]);

        $commande = $this->venteValidee($commercial, $produit, 2);

        $this->assertEquals(2000, $commande->commission_commercial);
        $this->assertDatabaseHas('commerciaux', ['user_id' => $commercial->id, 'solde_portefeuille' => 2000]);
        $this->assertDatabaseHas('transactions_portefeuille_commerciaux', [
            'commercial_id' => $commercial->id, 'commande_id' => $commande->id, 'type' => 'credit', 'montant' => 2000,
        ]);
    }

    public function test_un_commercial_independant_touche_2500_par_defaut(): void
    {
        $commercial = $this->commercial(CATEGORIE_COMMERCIAL_INDEPENDANT);
        $produit = $this->creerProduitPhysique(['quantite_stock' => 10]);

        $commande = $this->venteValidee($commercial, $produit, 2);

        $this->assertEquals(5000, $commande->commission_commercial);
        $this->assertDatabaseHas('commerciaux', ['user_id' => $commercial->id, 'solde_portefeuille' => 5000]);
    }

    public function test_un_commercial_independant_touche_le_montant_fixe_sur_le_produit(): void
    {
        $commercial = $this->commercial(CATEGORIE_COMMERCIAL_INDEPENDANT);
        $produit = $this->creerProduitPhysique(['quantite_stock' => 10, 'commission_commercial_independant' => 4000]);

        $commande = $this->venteValidee($commercial, $produit, 1);

        $this->assertEquals(4000, $commande->commission_commercial);

        // Figée : changer ensuite le montant du produit ou le type ne modifie pas cette vente.
        $produit->update(['commission_commercial_independant' => 9000]);
        Commercial::where('user_id', $commercial->id)->update(['categorie_commercial' => CATEGORIE_COMMERCIAL_INTERNE]);
        $commande->update(['statut_commande' => STATUT_COMMANDE_LIVREE]);

        $this->actingAs($commercial)->getJson('/api/v1/commercial/espace/statistiques?periode=tout')
            ->assertOk()
            ->assertJsonPath('data.commission_totale', 4000);
    }

    public function test_ladmin_fixe_la_commission_independant_a_la_publication(): void
    {
        $admin = $this->creerAdmin();
        $categorie = $this->creerProduitPhysique()->categorie_id;

        $reponse = $this->actingAs($admin)->postJson('/api/v1/produits', [
            'categorie_id' => $categorie, 'nom_produit' => 'HP EliteBook', 'prix' => 150000, 'quantite_stock' => 3,
            'commission_commercial_independant' => 3000,
        ])->assertCreated();

        $produit = Produit::findOrFail($reponse->json('data.id'));
        $this->assertEquals(3000, $produit->commission_commercial_independant);

        $this->actingAs($admin)->putJson("/api/v1/produits/{$produit->id}", [
            'categorie_id' => $categorie, 'nom_produit' => 'HP EliteBook', 'prix' => 150000, 'quantite_stock' => 3,
            'commission_commercial_independant' => 3500,
        ])->assertOk();
        $this->assertEquals(3500, $produit->fresh()->commission_commercial_independant);
    }

    public function test_ladmin_doit_choisir_le_type_et_le_numero_du_commercial(): void
    {
        $admin = $this->creerAdmin();

        $this->actingAs($admin)->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'Koné', 'type_utilisateur' => ROLE_COMMERCIAL,
        ])->assertUnprocessable()->assertJsonValidationErrors(['telephone', 'categorie_commercial'], 'error.fields');

        $this->actingAs($admin)->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'Koné', 'telephone' => '07 11 22 33 55', 'type_utilisateur' => ROLE_COMMERCIAL, 'categorie_commercial' => 'autre',
        ])->assertUnprocessable()->assertJsonValidationErrors('categorie_commercial', 'error.fields');
    }

    public function test_un_commercial_cree_par_ladmin_se_connecte_par_telephone(): void
    {
        $admin = $this->creerAdmin();

        $userId = $this->actingAs($admin)->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'Koné', 'telephone' => '07 11 22 33 66', 'type_utilisateur' => ROLE_COMMERCIAL,
            'categorie_commercial' => CATEGORIE_COMMERCIAL_INTERNE,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/auth/telephone/otp', ['telephone' => '07 11 22 33 66', 'type_utilisateur' => ROLE_COMMERCIAL])
            ->assertOk()
            ->assertJsonPath('data.compte_existant', true)
            ->assertJsonPath('data.user_id', $userId);

        // Numéro inconnu : aucun compte n'est créé, l'app affiche « contacte l'administration ».
        $this->postJson('/api/v1/auth/telephone/otp', ['telephone' => '07 99 99 99 99', 'type_utilisateur' => ROLE_COMMERCIAL])
            ->assertOk()
            ->assertJsonPath('data.compte_existant', false);
    }

    public function test_ladmin_change_le_type_dun_commercial(): void
    {
        $admin = $this->creerAdmin();
        $commercial = $this->creerCommercial();

        $this->actingAs($admin)->patchJson("/api/v1/coordinateur/commerciaux/{$commercial->id}/profil", [
            'categorie_commercial' => CATEGORIE_COMMERCIAL_INDEPENDANT,
        ])->assertOk();

        $this->assertDatabaseHas('commerciaux', ['user_id' => $commercial->id, 'categorie_commercial' => CATEGORIE_COMMERCIAL_INDEPENDANT]);
        $this->actingAs($admin)->getJson("/api/v1/coordinateur/commerciaux/{$commercial->id}")
            ->assertJsonPath('data.categorie_commercial', CATEGORIE_COMMERCIAL_INDEPENDANT);
    }

    public function test_seul_ladmin_peut_deconnecter_un_commercial(): void
    {
        $admin = $this->creerAdmin();
        $commercial = $this->creerCommercial();
        $commercial->createToken('commercial-web');
        $commercial->createToken('commercial-mobile');

        $this->actingAs($this->creerCoordinateur())->postJson("/api/v1/admin/utilisateurs/{$commercial->id}/deconnecter")->assertForbidden();
        $this->assertSame(2, $commercial->tokens()->count());

        $this->actingAs($admin)->postJson("/api/v1/admin/utilisateurs/{$commercial->id}/deconnecter")
            ->assertOk()
            ->assertJsonPath('data.sessions_fermees', 2);

        $this->assertSame(0, $commercial->tokens()->count());
        $this->assertDatabaseHas('users', ['id' => $commercial->id, 'statut_compte' => STATUT_COMPTE_ACTIF]);
    }
}
