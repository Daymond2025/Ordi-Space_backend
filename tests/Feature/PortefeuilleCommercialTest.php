<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * "Mes paiements" (app Commercial) — crédit FIXE (COMMISSION_COMMERCIAL_PAR_VENTE)
 * à la validation d'une commande (Commande::crediterCommissionCommercialSiEligible()),
 * repris si elle est ensuite annulée (reprendreCommissionCommercialSiEligible()).
 * Voir Api\Commercial\EspaceController::portefeuille().
 */
class PortefeuilleCommercialTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommandeCommercial(User $commercial): Commande
    {
        // commission_agent volontairement non précisé (défaut 1000, sans
        // effet sur ce que touche réellement le commercial — toujours
        // COMMISSION_COMMERCIAL_PAR_VENTE, quel que soit le produit).
        $produit = $this->creerProduitPhysique();
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        return Commande::findOrFail($reponse->json('data.id'));
    }

    public function test_la_validation_credite_le_montant_fixe_quel_que_soit_le_produit(): void
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $produitCher = $this->creerProduitPhysique(['commission_agent' => 8000, 'nom_produit' => 'Cher']);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);
        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produitCher->id, 'quantite' => 1]],
        ]);
        $commande = Commande::findOrFail($reponse->json('data.id'));

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();

        // Le produit a commission_agent=8000, mais le commercial ne touche
        // que le montant fixe (1000) — jamais ce champ par produit.
        $this->assertDatabaseHas('commerciaux', ['user_id' => $commercial->id, 'solde_portefeuille' => 1000]);
        $this->assertDatabaseHas('transactions_portefeuille_commerciaux', [
            'commercial_id' => $commercial->id, 'commande_id' => $commande->id, 'type' => 'credit', 'montant' => 1000, 'libelle' => 'Commande validé',
        ]);
    }

    public function test_annuler_une_commande_validee_reprend_la_commission(): void
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommandeCommercial($commercial);

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();
        $this->assertDatabaseHas('commerciaux', ['user_id' => $commercial->id, 'solde_portefeuille' => 1000]);

        // Le commercial lui-même peut annuler sa propre commande (périmètre 3 statuts).
        $this->actingAs($commercial)->postJson("/api/v1/commandes/{$commande->id}/statut", [
            'statut_commande' => 'annulee',
        ])->assertOk();

        $this->assertDatabaseHas('commerciaux', ['user_id' => $commercial->id, 'solde_portefeuille' => 0]);
        // "Commande annulée", jamais "Retrait effectué" — réservé au vrai
        // retrait Mobile Money (voir les tests de demande de retrait).
        $this->assertDatabaseHas('transactions_portefeuille_commerciaux', [
            'commercial_id' => $commercial->id, 'commande_id' => $commande->id, 'type' => 'debit', 'montant' => 1000, 'libelle' => 'Commande annulée',
        ]);
    }

    public function test_annuler_une_commande_jamais_validee_ne_cree_aucune_reprise(): void
    {
        $commercial = $this->creerCommercial();
        $commande = $this->creerCommandeCommercial($commercial);

        $this->actingAs($commercial)->postJson("/api/v1/commandes/{$commande->id}/statut", [
            'statut_commande' => 'annulee',
        ])->assertOk();

        $this->assertDatabaseMissing('transactions_portefeuille_commerciaux', ['commande_id' => $commande->id]);
    }

    public function test_la_validation_est_idempotente_si_rejouee(): void
    {
        $commercial = $this->creerCommercial();
        $commande = $this->creerCommandeCommercial($commercial);

        $commande->crediterCommissionCommercialSiEligible();
        $commande->crediterCommissionCommercialSiEligible();

        $this->assertDatabaseHas('commerciaux', ['user_id' => $commercial->id, 'solde_portefeuille' => 1000]);
        $this->assertEquals(1, \App\Models\TransactionPortefeuilleCommercial::where('commande_id', $commande->id)->count());
    }

    public function test_le_portefeuille_expose_le_solde_et_lhistorique_avec_le_nom_du_client(): void
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommandeCommercial($commercial);
        $commande->load('client.user');

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();

        $reponse = $this->actingAs($commercial)->getJson('/api/v1/commercial/espace/portefeuille?periode=tout');

        $reponse->assertOk();
        $reponse->assertJsonPath('data.solde', 1000);
        $reponse->assertJsonPath('data.disponible', 1000);
        $this->assertCount(1, $reponse->json('data.transactions'));
        $this->assertSame('Commande validé', $reponse->json('data.transactions.0.libelle'));
        $this->assertNotNull($reponse->json('data.transactions.0.client_nom'));
    }

    public function test_un_commercial_ne_voit_pas_le_portefeuille_dun_autre(): void
    {
        $commercialA = $this->creerCommercial();
        $commercialB = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommandeCommercial($commercialA);
        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();

        $reponse = $this->actingAs($commercialB)->getJson('/api/v1/commercial/espace/portefeuille?periode=tout');

        $reponse->assertOk();
        $reponse->assertJsonPath('data.solde', 0);
        $this->assertCount(0, $reponse->json('data.transactions'));
    }

    // --- Demande de retrait -------------------------------------------------

    private function commercialAvecSolde(int $solde): User
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();

        // Autant de commandes validées que nécessaire pour atteindre le solde visé.
        for ($i = 0; $i < $solde / 1000; $i++) {
            $commande = $this->creerCommandeCommercial($commercial);
            $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();
        }

        return $commercial;
    }

    public function test_un_commercial_peut_demander_un_retrait_dans_la_limite_de_son_solde(): void
    {
        $commercial = $this->commercialAvecSolde(2000);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commercial/espace/retraits', [
            'montant' => 1500, 'operateur' => 'Orange', 'telephone' => '0700000000',
        ]);

        $reponse->assertCreated();
        $reponse->assertJsonPath('data.statut', 'en_attente');
        $this->assertDatabaseHas('demandes_retrait', ['user_id' => $commercial->id, 'montant' => 1500, 'statut' => 'en_attente']);

        // Le disponible tient compte de la demande en attente (pas encore débitée du solde).
        $portefeuille = $this->actingAs($commercial)->getJson('/api/v1/commercial/espace/portefeuille?periode=tout');
        $portefeuille->assertJsonPath('data.solde', 2000);
        $portefeuille->assertJsonPath('data.disponible', 500);
    }

    public function test_un_commercial_ne_peut_pas_demander_plus_que_son_disponible(): void
    {
        $commercial = $this->commercialAvecSolde(1000);

        $this->actingAs($commercial)->postJson('/api/v1/commercial/espace/retraits', [
            'montant' => 2000, 'operateur' => 'Orange', 'telephone' => '0700000000',
        ])->assertUnprocessable();
    }

    public function test_deux_demandes_ne_peuvent_pas_ensemble_depasser_le_solde(): void
    {
        $commercial = $this->commercialAvecSolde(1000);

        $this->actingAs($commercial)->postJson('/api/v1/commercial/espace/retraits', [
            'montant' => 1000, 'operateur' => 'Orange', 'telephone' => '0700000000',
        ])->assertCreated();

        $this->actingAs($commercial)->postJson('/api/v1/commercial/espace/retraits', [
            'montant' => 1000, 'operateur' => 'Orange', 'telephone' => '0700000000',
        ])->assertUnprocessable();
    }

    public function test_un_commercial_peut_annuler_sa_demande_en_attente(): void
    {
        $commercial = $this->commercialAvecSolde(1000);
        $creation = $this->actingAs($commercial)->postJson('/api/v1/commercial/espace/retraits', [
            'montant' => 1000, 'operateur' => 'Orange', 'telephone' => '0700000000',
        ]);
        $id = $creation->json('data.id');

        $this->actingAs($commercial)->postJson("/api/v1/commercial/espace/retraits/{$id}/annuler")->assertOk();

        $this->assertDatabaseHas('demandes_retrait', ['id' => $id, 'statut' => 'annule']);
        $portefeuille = $this->actingAs($commercial)->getJson('/api/v1/commercial/espace/portefeuille?periode=tout');
        $portefeuille->assertJsonPath('data.disponible', 1000);
    }

    public function test_ladmin_validant_le_retrait_debite_reellement_le_solde_et_cree_la_transaction(): void
    {
        $commercial = $this->commercialAvecSolde(1000);
        $admin = $this->creerAdmin();
        $creation = $this->actingAs($commercial)->postJson('/api/v1/commercial/espace/retraits', [
            'montant' => 1000, 'operateur' => 'Orange', 'telephone' => '0700000000',
        ]);
        $id = $creation->json('data.id');

        $this->actingAs($admin)->postJson("/api/v1/admin/retraits/{$id}/valider", ['reference' => 'TX-REEL-001'])->assertOk();

        $this->assertDatabaseHas('commerciaux', ['user_id' => $commercial->id, 'solde_portefeuille' => 0]);
        $this->assertDatabaseHas('transactions_portefeuille_commerciaux', [
            'commercial_id' => $commercial->id, 'type' => 'debit', 'montant' => 1000, 'libelle' => 'Retrait effectué',
        ]);
    }

    public function test_un_autre_commercial_ne_peut_pas_annuler_la_demande_dautrui(): void
    {
        $commercialA = $this->commercialAvecSolde(1000);
        $commercialB = $this->creerCommercial();
        $creation = $this->actingAs($commercialA)->postJson('/api/v1/commercial/espace/retraits', [
            'montant' => 1000, 'operateur' => 'Orange', 'telephone' => '0700000000',
        ]);
        $id = $creation->json('data.id');

        $this->actingAs($commercialB)->postJson("/api/v1/commercial/espace/retraits/{$id}/annuler")->assertForbidden();
    }
}
