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
 * GET /fournisseurs (Centre des opérations) — compteurs de commandes ajoutés
 * par sous-requête corrélée dans FournisseurController::index().
 */
class FournisseurListeTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommandePour(int $produitId, int $clientId, string $statut): Commande
    {
        $commercial = User::factory()->create(['type_utilisateur' => ROLE_COMMERCIAL]);
        Commercial::create(['user_id' => $commercial->id, 'type_commercial' => 'humain']);
        $canal = CanalVente::firstOrCreate(['nom_canal' => 'Boutique en ligne']);

        $commande = Commande::create([
            'client_id' => $clientId,
            'commercial_id' => $commercial->id,
            'canal_vente_id' => $canal->id,
            'statut_commande' => $statut,
            'montant_total' => 10000,
            'date_commande' => now(),
        ]);

        LigneCommande::create([
            'commande_id' => $commande->id,
            'produit_id' => $produitId,
            'quantite' => 1,
            'prix_unitaire' => 10000,
        ]);

        return $commande;
    }

    public function test_la_liste_expose_le_compte_de_commandes_en_attente_et_le_total(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();
        $client = $this->creerClient();

        $this->creerCommandePour($produit->id, $client->id, STATUT_COMMANDE_EN_ATTENTE);
        $this->creerCommandePour($produit->id, $client->id, STATUT_COMMANDE_EN_ATTENTE);
        $this->creerCommandePour($produit->id, $client->id, STATUT_COMMANDE_LIVREE);
        $this->creerCommandePour($produit->id, $client->id, STATUT_COMMANDE_ANNULEE);
        $this->creerCommandePour($produit->id, $client->id, STATUT_COMMANDE_LIVREE);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/fournisseurs');

        $reponse->assertOk();
        $ligne = collect($reponse->json('data.data'))->firstWhere('user_id', $produit->fournisseur_id);

        $this->assertNotNull($ligne);
        $this->assertSame(2, $ligne['commandes_en_attente_count']);
        $this->assertSame(5, $ligne['commandes_total_count']);
        $this->assertNotNull($ligne['derniere_commande_le']);
    }

    public function test_un_fournisseur_sans_commande_remonte_des_compteurs_a_zero(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $this->creerFournisseur();

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/fournisseurs');

        $reponse->assertOk();
        $ligne = collect($reponse->json('data.data'))->first();

        $this->assertSame(0, $ligne['commandes_en_attente_count']);
        $this->assertSame(0, $ligne['commandes_total_count']);
        $this->assertNull($ligne['derniere_commande_le']);
    }
}
