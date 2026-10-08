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
 * GET /commercial/espace/statistiques-detail — écran "Statistiques"
 * (Compte > Statistique), pas de mockup fourni : même structure que
 * FournisseurController::statistiques() (tableau de bord d'UN fournisseur),
 * adaptée au Commercial.
 */
class CommercialStatistiquesDetailTest extends TestCase
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
            'commande_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 1,
            'prix_unitaire' => $produit->prix,
        ]);

        return $commande;
    }

    public function test_le_tableau_de_bord_expose_les_compteurs_et_la_commission_fixe(): void
    {
        $commercial = $this->creerCommercial();
        $autreCommercial = $this->creerCommercialTiers();

        $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_LIVREE);
        $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_LIVREE);
        $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_VALIDEE);
        $this->creerCommandePourCommercial($commercial, STATUT_COMMANDE_ANNULEE);
        // Commande d'un autre commercial — ne doit jamais apparaître ici.
        $this->creerCommandePourCommercial($autreCommercial, STATUT_COMMANDE_LIVREE);

        $reponse = $this->actingAs($commercial)->getJson('/api/v1/commercial/espace/statistiques-detail?periode=tout');

        $reponse->assertOk();
        $donnees = $reponse->json('data');
        $this->assertSame(4, $donnees['commandes_total']);
        $this->assertSame(1, $donnees['commandes_validees']);
        $this->assertSame(2, $donnees['commandes_livrees']);
        $this->assertSame(1, $donnees['commandes_annulees']);
        // 2 commandes livrées × 1000 (COMMISSION_COMMERCIAL_PAR_VENTE).
        $this->assertEquals(2000, $donnees['commission_totale']);
        $this->assertSame(4, $donnees['produits_vendus']);
        $this->assertNull($donnees['croissance_pourcentage']); // "tout" : pas de période précédente comparable.
    }

    public function test_produits_les_plus_vendus_classes_par_quantite(): void
    {
        $commercial = $this->creerCommercial();
        $produitPopulaire = $this->creerProduitPhysique(['nom_produit' => 'Dell Populaire', 'prix' => 100000]);
        $produitRare = $this->creerProduitPhysique(['nom_produit' => 'Dell Rare', 'prix' => 50000]);
        $canal = CanalVente::firstOrCreate(['nom_canal' => 'Boutique en ligne']);

        foreach ([3, 1] as $i => $quantite) {
            $produit = $i === 0 ? $produitPopulaire : $produitRare;
            $client = $this->creerClient();
            $commande = Commande::create([
                'client_id' => $client->id, 'commercial_id' => $commercial->id, 'canal_vente_id' => $canal->id,
                'statut_commande' => STATUT_COMMANDE_LIVREE, 'montant_total' => $produit->prix * $quantite, 'date_commande' => now(),
            ]);
            LigneCommande::create([
                'commande_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => $quantite, 'prix_unitaire' => $produit->prix,
            ]);
        }

        $reponse = $this->actingAs($commercial)->getJson('/api/v1/commercial/espace/statistiques-detail?periode=tout');

        $produits = $reponse->json('data.produits_plus_vendus');
        $this->assertSame('Dell Populaire', $produits[0]['nom_produit']);
        $this->assertSame(3, $produits[0]['quantite_vendue']);
        $this->assertEquals(300000, $produits[0]['montant']);
    }

    public function test_un_coordinateur_ne_peut_pas_appeler_cette_route(): void
    {
        $coordinateur = $this->creerCoordinateur();

        $this->actingAs($coordinateur)
            ->getJson('/api/v1/commercial/espace/statistiques-detail')
            ->assertForbidden();
    }
}
