<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\LienAffilie;
use App\Models\Localite;
use App\Models\VenteBoutique;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * App Maintenancier : le technicien de maintenance a accès à la même
 * Boutique d'affiliation que le Livreur (BoutiqueController, élargi) — même
 * génération de lien, même calcul de commission (VenteBoutique::enregistrer()
 * ne dépend d'aucun rôle), voir BoutiqueLienAffilieTest/
 * BoutiqueCommandeFournisseurTest pour le comportement Livreur équivalent.
 */
class BoutiqueTechnicienTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_le_technicien_genere_un_lien_affilie_pour_un_produit(): void
    {
        $technicien = $this->creerTechnicien();
        $produit = $this->creerProduitPhysique(['commission_apporteur' => 15000]);

        $reponse = $this->actingAs($technicien)->postJson("/api/v1/boutique/produits/{$produit->id}/lien");

        $reponse->assertOk();
        $code = $reponse->json('data.code');
        $this->assertNotEmpty($code);
        $this->assertDatabaseHas('liens_affilies', [
            'produit_id' => $produit->id,
            'livreur_id' => $technicien->id,
            'code' => $code,
        ]);
    }

    public function test_une_vente_via_le_lien_du_technicien_calcule_la_commission_comme_pour_un_livreur(): void
    {
        $technicien = $this->creerTechnicien();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5, 'prix' => 100000, 'commission_apporteur' => 15000]);

        $reponse = $this->actingAs($technicien)->postJson('/api/v1/boutique/commandes', [
            'produit_id' => $produit->id,
            'nom' => 'Yao Brou Kouadio',
            'telephone' => '07 00 11 22 33',
            'localite_id' => Localite::where('nom', 'Cocody')->value('id'),
        ]);

        $reponse->assertCreated();
        $commande = Commande::findOrFail($reponse->json('data.reference'));

        $vente = VenteBoutique::where('commande_id', $commande->id)->firstOrFail();
        $this->assertSame($technicien->id, $vente->livreur_id);
        $this->assertSame('manuelle', $vente->source);
        $this->assertEquals(15000, $vente->commission);
    }

    public function test_un_technicien_voit_son_profil_boutique(): void
    {
        $technicien = $this->creerTechnicien(['telephone' => '+2250700000707']);

        $reponse = $this->actingAs($technicien)->getJson('/api/v1/boutique/profil');

        $reponse->assertOk();
        $this->assertSame('+2250700000707', $reponse->json('data.livreur.telephone'));
    }

    public function test_un_technicien_ne_voit_pas_les_liens_dun_autre_technicien(): void
    {
        $technicienA = $this->creerTechnicien();
        $technicienB = $this->creerTechnicien();
        $produit = $this->creerProduitPhysique();
        LienAffilie::create(['produit_id' => $produit->id, 'livreur_id' => $technicienA->id, 'code' => 'abcdefgh']);

        $reponse = $this->actingAs($technicienB)->getJson('/api/v1/boutique/liens');

        $reponse->assertOk();
        $this->assertCount(0, $reponse->json('data'));
    }

    /**
     * Portefeuille/retraits (PortefeuilleController) — contrôleur séparé de
     * BoutiqueController, avec son propre verrou (désormais sur User, pas
     * Livreur) : à vérifier indépendamment pour un technicien.
     */
    public function test_un_technicien_consulte_son_portefeuille_et_demande_un_retrait(): void
    {
        $technicien = $this->creerTechnicien();
        $admin = $this->creerAdmin();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5, 'prix' => 100000, 'commission_apporteur' => 15000]);

        $commande = $this->actingAs($technicien)->postJson('/api/v1/boutique/commandes', [
            'produit_id' => $produit->id,
            'nom' => 'Yao Brou Kouadio',
            'telephone' => '07 00 11 22 33',
            'localite_id' => Localite::where('nom', 'Cocody')->value('id'),
        ])->json('data.reference');

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commande}/statut", ['statut_commande' => STATUT_COMMANDE_VALIDEE])->assertOk();

        $portefeuille = $this->actingAs($technicien)->getJson('/api/v1/boutique/portefeuille');
        $portefeuille->assertOk();
        $this->assertSame(15000, $portefeuille->json('data.disponible'));

        $retrait = $this->actingAs($technicien)->postJson('/api/v1/boutique/retraits', [
            'montant' => 10000, 'operateur' => 'Orange', 'telephone' => '07 00 11 22 33',
        ]);
        $retrait->assertCreated();
        $this->assertSame(STATUT_RETRAIT_EN_ATTENTE, $retrait->json('data.statut'));
        $this->assertSame(5000, $this->actingAs($technicien)->getJson('/api/v1/boutique/portefeuille')->json('data.disponible'));
    }
}
