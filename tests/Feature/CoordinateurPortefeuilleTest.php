<?php

namespace Tests\Feature;

use App\Models\Fournisseur;
use App\Models\TransactionPortefeuilleFournisseur;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * GET /coordinateur/portefeuille — portefeuille global (bouton "Paiement" de
 * la BottomNav), agrège les crédits de TOUS les fournisseurs, contrairement à
 * FournisseurController::portefeuille() qui est scopé à un seul.
 */
class CoordinateurPortefeuilleTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCredit(Fournisseur $fournisseur, float $montant, string $statut): void
    {
        TransactionPortefeuilleFournisseur::create([
            'fournisseur_id' => $fournisseur->user_id,
            'type' => TYPE_TRANSACTION_PORTEFEUILLE_CREDIT,
            'statut' => $statut,
            'montant' => $montant,
            'motif' => 'Test',
            'solde_apres' => $montant,
            'date_transaction' => now(),
        ]);
    }

    public function test_le_portefeuille_global_agrege_plusieurs_fournisseurs(): void
    {
        $fournisseurA = Fournisseur::findOrFail($this->creerFournisseur()->id);
        $fournisseurB = Fournisseur::findOrFail($this->creerFournisseur()->id);
        $fournisseurA->update(['solde_portefeuille' => 20000]);
        $fournisseurB->update(['solde_portefeuille' => 15000]);

        $this->creerCredit($fournisseurA, 20000, STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE);
        $this->creerCredit($fournisseurB, 15000, STATUT_TRANSACTION_PORTEFEUILLE_PAYE);

        $coordinateur = $this->creerCoordinateur();
        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/portefeuille');

        $reponse->assertOk();
        $reponse->assertJsonPath('data.solde_general', 35000);
        $reponse->assertJsonPath('data.total_general', 35000);
        $reponse->assertJsonPath('data.total_en_attente', 20000);
        $reponse->assertJsonPath('data.total_paye', 15000);
        $this->assertCount(2, $reponse->json('data.transactions.data'));

        $noms = collect($reponse->json('data.transactions.data'))->pluck('nom_fournisseur');
        $this->assertTrue($noms->contains($fournisseurA->nom_entreprise));
        $this->assertTrue($noms->contains($fournisseurB->nom_entreprise));
    }

    public function test_le_filtre_statut_restreint_aux_credits_en_attente(): void
    {
        $fournisseur = Fournisseur::findOrFail($this->creerFournisseur()->id);
        $this->creerCredit($fournisseur, 10000, STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE);
        $this->creerCredit($fournisseur, 5000, STATUT_TRANSACTION_PORTEFEUILLE_PAYE);

        $coordinateur = $this->creerCoordinateur();

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/portefeuille?statut=en_attente');
        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data.transactions.data'));
        $this->assertSame('en_attente', $reponse->json('data.transactions.data')[0]['statut']);

        // Les 3 totaux restent sur l'ensemble de la période, indépendamment
        // du filtre ?statut= appliqué à la liste.
        $reponse->assertJsonPath('data.total_general', 15000);
        $reponse->assertJsonPath('data.total_en_attente', 10000);
        $reponse->assertJsonPath('data.total_paye', 5000);
    }

    public function test_montant_en_attente_est_expose_sur_la_liste_des_fournisseurs(): void
    {
        $fournisseur = Fournisseur::findOrFail($this->creerFournisseur()->id);
        $this->creerCredit($fournisseur, 12000, STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE);
        $this->creerCredit($fournisseur, 5000, STATUT_TRANSACTION_PORTEFEUILLE_PAYE);

        $coordinateur = $this->creerCoordinateur();
        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/fournisseurs');

        $reponse->assertOk();
        $ligne = collect($reponse->json('data.data'))->firstWhere('user_id', $fournisseur->user_id);
        $this->assertEquals(12000, $ligne['montant_en_attente']);
    }

    public function test_un_fournisseur_ou_commercial_ne_peut_pas_consulter_le_portefeuille_global(): void
    {
        $fournisseurUser = $this->creerFournisseur();
        $commercial = $this->creerCommercial();

        $this->actingAs($fournisseurUser)->getJson('/api/v1/coordinateur/portefeuille')->assertForbidden();
        $this->actingAs($commercial)->getJson('/api/v1/coordinateur/portefeuille')->assertForbidden();
    }

    public function test_le_detail_expose_les_deux_montants_distincts_et_le_gerant(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $client = $this->creerClient();
        $ligne = $this->creerAchatLivre($client);
        $fournisseur = Fournisseur::findOrFail($ligne->produit->fournisseur_id);
        $fournisseur->update(['nom_gerant' => 'Awa Koffi']);

        $transaction = TransactionPortefeuilleFournisseur::create([
            'fournisseur_id' => $fournisseur->user_id,
            'type' => TYPE_TRANSACTION_PORTEFEUILLE_CREDIT,
            'statut' => STATUT_TRANSACTION_PORTEFEUILLE_PAYE,
            'reference_paiement' => 'WAVE-2026-0912',
            'montant' => 15000,
            'motif' => 'Test',
            'commande_id' => $ligne->commande_id,
            'solde_apres' => 15000,
            'date_transaction' => now(),
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/coordinateur/portefeuille/transactions/{$transaction->id}");

        $reponse->assertOk();
        $reponse->assertJsonPath('data.montant', 15000);
        $reponse->assertJsonPath('data.prix_vente_total', (int) ($ligne->prix_unitaire * $ligne->quantite));
        $reponse->assertJsonPath('data.nom_gerant', 'Awa Koffi');
        $reponse->assertJsonPath('data.reference_paiement', 'WAVE-2026-0912');
    }

    /**
     * Aucune valeur fabriquée tant que la vente n'a pas été réellement réglée
     * (pas de moyen de paiement intégré — la référence est saisie par le
     * coordinateur au moment du paiement hors app, cf. payer-tout).
     */
    public function test_le_detail_n_expose_aucune_reference_tant_que_la_vente_est_en_attente(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $fournisseur = Fournisseur::findOrFail($this->creerFournisseur()->id);
        $this->creerCredit($fournisseur, 15000, STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE);
        $transaction = TransactionPortefeuilleFournisseur::first();

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/coordinateur/portefeuille/transactions/{$transaction->id}");

        $reponse->assertOk();
        $reponse->assertJsonPath('data.reference_paiement', null);
    }

    public function test_le_recu_retourne_un_vrai_pdf(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $fournisseur = Fournisseur::findOrFail($this->creerFournisseur()->id);
        $this->creerCredit($fournisseur, 15000, STATUT_TRANSACTION_PORTEFEUILLE_PAYE);
        $transaction = TransactionPortefeuilleFournisseur::first();

        $reponse = $this->actingAs($coordinateur)->get("/api/v1/coordinateur/portefeuille/transactions/{$transaction->id}/recu");

        $reponse->assertOk();
        $reponse->assertHeader('content-type', 'application/pdf');
    }

    public function test_un_fournisseur_ou_commercial_ne_peut_pas_consulter_le_detail_ou_le_recu(): void
    {
        $fournisseurUser = $this->creerFournisseur();
        $commercial = $this->creerCommercial();
        $this->creerCredit(Fournisseur::findOrFail($fournisseurUser->id), 15000, STATUT_TRANSACTION_PORTEFEUILLE_PAYE);
        $transaction = TransactionPortefeuilleFournisseur::first();

        $this->actingAs($fournisseurUser)->getJson("/api/v1/coordinateur/portefeuille/transactions/{$transaction->id}")->assertForbidden();
        $this->actingAs($commercial)->getJson("/api/v1/coordinateur/portefeuille/transactions/{$transaction->id}/recu")->assertForbidden();
    }
}
