<?php

namespace Tests\Feature;

use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\Commercial;
use App\Models\LigneCommande;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * GET /admin/commerciaux/tableau-de-bord — vue d'ensemble de l'espace
 * Commerciaux côté Admin (Admin\CommercialController::tableauDeBord()),
 * même esprit que Admin\LivreurController::tableauDeBord().
 */
class AdminCommercialTableauDeBordTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommandePourCommercial(User $commercial, string $statut): Commande
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();
        $canal = CanalVente::firstOrCreate(['nom_canal' => 'Boutique en ligne']);

        $commande = Commande::create([
            'client_id' => $client->id,
            'commercial_id' => $commercial->id,
            'canal_vente_id' => $canal->id,
            'statut_commande' => $statut,
            'montant_total' => $produit->prix,
            'date_commande' => now(),
        ]);

        LigneCommande::create([
            'commande_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire' => $produit->prix,
        ]);

        // "Commande validé" côté portefeuille — même chemin que la vraie
        // validation (CommandeController::valider() dans le flux normal),
        // appelé directement ici pour ne pas dépendre du Coordinateur.
        if ($statut === STATUT_COMMANDE_LIVREE) {
            $commande->crediterCommissionCommercialSiEligible();
        }

        return $commande;
    }

    public function test_ladmin_voit_le_tableau_de_bord_commerciaux(): void
    {
        $admin = $this->creerAdmin();

        $commercialActif = $this->creerCommercial(['statut_compte' => STATUT_COMPTE_ACTIF]);
        $this->creerCommercial(['statut_compte' => STATUT_COMPTE_SUSPENDU]);
        // Agent IA : ne doit compter dans aucun chiffre de ce tableau de bord.
        $this->creerAgentIa();

        $this->creerCommandePourCommercial($commercialActif, STATUT_COMMANDE_LIVREE);
        $this->creerCommandePourCommercial($commercialActif, STATUT_COMMANDE_VALIDEE);
        $this->creerCommandePourCommercial($commercialActif, STATUT_COMMANDE_ANNULEE);

        $reponse = $this->actingAs($admin)->getJson('/api/v1/admin/commerciaux/tableau-de-bord');
        $reponse->assertOk();
        $donnees = $reponse->json('data');

        $this->assertSame(2, $donnees['commerciaux']['total']);
        $this->assertSame(1, $donnees['commerciaux']['actifs']);
        $this->assertSame(1, $donnees['commerciaux']['suspendus']);
        $this->assertSame(3, $donnees['commandes']['total']);
        $this->assertSame(1, $donnees['commandes']['validees']);
        $this->assertSame(1, $donnees['commandes']['livrees']);
        $this->assertSame(1, $donnees['commandes']['annulees']);
        // 1 commande livrée × COMMISSION_COMMERCIAL_PAR_VENTE (1000).
        $this->assertEquals(1000, $donnees['commissions']['total_credite']);
        $this->assertEquals(1000, $donnees['commissions']['solde_total_portefeuilles']);
        $this->assertSame(1, count(array_filter($donnees['top_commerciaux'], fn ($c) => $c['user_id'] === $commercialActif->id)));
    }

    public function test_un_non_administrateur_ne_peut_pas_consulter_le_tableau_de_bord_commerciaux(): void
    {
        $fournisseur = $this->creerFournisseur();

        $this->actingAs($fournisseur)
            ->getJson('/api/v1/admin/commerciaux/tableau-de-bord')
            ->assertForbidden();
    }

    public function test_ladmin_peut_creer_un_compte_commercial(): void
    {
        $admin = $this->creerAdmin();

        $reponse = $this->actingAs($admin)->postJson('/api/v1/admin/utilisateurs', [
            'nom' => 'Koné',
            'prenom' => 'Awa',
            'email' => 'awa.kone@example.com',
            'password' => 'MotDePasse1',
            'type_utilisateur' => ROLE_COMMERCIAL,
            'nom_entreprise' => 'Awa Distrib',
            'localisation' => 'Abidjan, Marcory',
        ]);

        $reponse->assertCreated();
        $userId = $reponse->json('data.id');

        $this->assertDatabaseHas('commerciaux', [
            'user_id' => $userId, 'type_commercial' => TYPE_COMMERCIAL_HUMAIN,
            'nom_entreprise' => 'Awa Distrib', 'localisation' => 'Abidjan, Marcory',
        ]);
        $this->assertTrue(User::find($userId)->hasRole(ROLE_COMMERCIAL));
    }

    public function test_ladmin_peut_modifier_le_profil_metier_dun_commercial(): void
    {
        $admin = $this->creerAdmin();
        $commercial = $this->creerCommercial();

        $this->actingAs($admin)
            ->patchJson("/api/v1/coordinateur/commerciaux/{$commercial->id}/profil", [
                'nom_entreprise' => 'Nouvelle entreprise', 'localisation' => 'Yamoussoukro',
            ])
            ->assertOk();

        $this->assertDatabaseHas('commerciaux', [
            'user_id' => $commercial->id, 'nom_entreprise' => 'Nouvelle entreprise', 'localisation' => 'Yamoussoukro',
        ]);
    }

    public function test_un_coordinateur_ne_peut_pas_modifier_le_profil_metier(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();

        $this->actingAs($coordinateur)
            ->patchJson("/api/v1/coordinateur/commerciaux/{$commercial->id}/profil", ['nom_entreprise' => 'X'])
            ->assertForbidden();
    }
}
