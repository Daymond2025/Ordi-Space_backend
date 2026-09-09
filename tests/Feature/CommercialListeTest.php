<?php

namespace Tests\Feature;

use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\LigneCommande;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * GET /coordinateur/commerciaux — écran "Les commerciaux" (Espace Agent,
 * bottombar).
 */
class CommercialListeTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommandePourCommercial(User $commercial, string $statut, ?float $commissionAgent = null): Commande
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique(['commission_agent' => $commissionAgent]);
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
            'commande_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 1,
            'prix_unitaire' => $produit->prix,
        ]);

        return $commande;
    }

    public function test_la_liste_calcule_les_compteurs_et_la_commission(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();

        $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_LIVREE, 1500);
        $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_LIVREE, 2500);
        $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_VALIDEE, 9999);
        $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_ANNULEE);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/commerciaux');

        $reponse->assertOk();
        $ligne = collect($reponse->json('data'))->firstWhere('user_id', $commercial->id);
        $this->assertNotNull($ligne);
        $this->assertTrue($ligne['actif']);
        $this->assertSame(4, $ligne['commandes_total']);
        $this->assertSame(1, $ligne['commandes_validees']);
        $this->assertSame(1, $ligne['commandes_annulees']);
        // Seules les 2 commandes LIVRÉES comptent (1500+2500), pas la validée à 9999.
        $this->assertEquals(4000, $ligne['commission_totale']);
    }

    public function test_actif_derive_du_statut_de_compte(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial(['statut_compte' => STATUT_COMPTE_SUSPENDU]);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/commerciaux');

        $ligne = collect($reponse->json('data'))->firstWhere('user_id', $commercial->id);
        $this->assertNotNull($ligne);
        $this->assertFalse($ligne['actif']);
    }

    public function test_l_agent_ia_n_apparait_pas_dans_la_liste(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $agentIa = $this->creerAgentIa();

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/commerciaux');

        $ligne = collect($reponse->json('data'))->firstWhere('user_id', $agentIa->id);
        $this->assertNull($ligne);
    }

    public function test_un_commercial_ne_peut_pas_consulter_la_liste(): void
    {
        $commercial = $this->creerCommercial();

        $this->actingAs($commercial)->getJson('/api/v1/coordinateur/commerciaux')->assertForbidden();
    }
}
