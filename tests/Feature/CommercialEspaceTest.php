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
 * GET /commercial/espace/statistiques — accueil de l'app Commercial (humain),
 * premier écran construit pour ce rôle (jusqu'ici seule la vue Coordinateur
 * sur un commercial existait, voir CommercialDetailTest). Même structure de
 * test que CommercialDetailTest (creerCommandePourCommercial), même calcul
 * de commission_totale que CommercialController::show(), mais ici en
 * self-service (le commercial consulte SES propres stats).
 */
class CommercialEspaceTest extends TestCase
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

    public function test_le_commercial_voit_ses_propres_statistiques(): void
    {
        $commercial = $this->creerCommercial();
        $autreCommercial = $this->creerCommercialTiers();

        $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_LIVREE, 1500);
        $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_VALIDEE, 9999);
        $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_ANNULEE);
        // Commande d'un autre commercial — ne doit jamais apparaître ici.
        $this->creerCommandePourCommercial($autreCommercial, STATUT_COMMANDE_LIVREE, 1500);

        $reponse = $this->actingAs($commercial)->getJson('/api/v1/commercial/espace/statistiques?periode=tout');

        $reponse->assertOk();
        $donnees = $reponse->json('data');
        $this->assertSame(3, $donnees['commandes_total']);
        $this->assertSame(1, $donnees['commandes_livrees']);
        $this->assertSame(1, $donnees['commandes_annulees']);
        $this->assertSame(1, $donnees['commandes_validees']);
        $this->assertSame(1, $donnees['commandes_en_cours']); // la "validée", ni livrée ni annulée.
        // Montant fixe (COMMISSION_COMMERCIAL_PAR_VENTE), jamais
        // commission_agent (passé à 1500 ci-dessus mais sans effet) : 1
        // commande livrée × 1000.
        $this->assertEquals(1000, $donnees['commission_totale']);
    }

    public function test_filtre_par_periode(): void
    {
        $commercial = $this->creerCommercial();
        $commande = $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_LIVREE, 1500);
        $commande->update(['date_commande' => now()->subWeeks(2)]);

        $reponse = $this->actingAs($commercial)->getJson('/api/v1/commercial/espace/statistiques?periode=semaine');

        $reponse->assertOk();
        $this->assertSame(0, $reponse->json('data.commandes_total'));
    }

    public function test_un_coordinateur_ne_peut_pas_appeler_cette_route(): void
    {
        $coordinateur = $this->creerCoordinateur();

        $this->actingAs($coordinateur)
            ->getJson('/api/v1/commercial/espace/statistiques')
            ->assertForbidden();
    }

    public function test_produits_actifs_regroupe_par_produit_avec_un_compteur_par_statut(): void
    {
        $commercial = $this->creerCommercial();
        $autreCommercial = $this->creerCommercialTiers();
        $produit = $this->creerProduitPhysique(['nom_produit' => 'HP 840 g5 core i5']);
        $canal = CanalVente::firstOrCreate(['nom_canal' => 'Boutique en ligne']);

        // 2 commandes livrées + 1 en cours (validée) + 1 annulée, toutes pour LE MÊME produit.
        foreach ([STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_ANNULEE] as $statut) {
            $client = $this->creerClient();
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
        }

        // Commande d'un autre commercial, même produit — ne doit pas compter ici.
        $this->creerCommandePourCommercial($autreCommercial, STATUT_COMMANDE_LIVREE);

        $reponse = $this->actingAs($commercial)->getJson('/api/v1/commercial/espace/produits-actifs');

        $reponse->assertOk();
        $donnees = $reponse->json('data');
        $this->assertCount(1, $donnees);
        $this->assertSame($produit->id, $donnees[0]['produit_id']);
        $this->assertSame('HP 840 g5 core i5', $donnees[0]['nom_produit']);
        $this->assertSame(4, $donnees[0]['statistiques']['recues']);
        $this->assertSame(2, $donnees[0]['statistiques']['livrees']);
        $this->assertSame(1, $donnees[0]['statistiques']['annulees']);
        $this->assertSame(1, $donnees[0]['statistiques']['en_cours']);
    }

    public function test_produits_actifs_est_vide_sans_commande(): void
    {
        $commercial = $this->creerCommercial();

        $reponse = $this->actingAs($commercial)->getJson('/api/v1/commercial/espace/produits-actifs');

        $reponse->assertOk();
        $this->assertSame([], $reponse->json('data'));
    }
}
