<?php

namespace Tests\Feature;

use App\Models\AcompteConfirmation;
use App\Models\Commande;
use App\Models\LienAffilie;
use App\Models\Livreur;
use App\Models\Localite;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\Feature\Concerns\PasseCommandePublique;
use Tests\TestCase;

/**
 * Paiement de confirmation (200 F sur Wave, non remboursable) d'une commande de la page
 * acheteur : la commande naît au webhook, le client ne doit plus que son reliquat à la
 * livraison, et l'Admin suit les confirmations jusqu'aux anomalies.
 */
class ConfirmationCommandeTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi, PasseCommandePublique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->creerAgentIa();
        $this->configurerWavePublic();
        $this->fauxWave();
    }

    private ?User $vendeur = null;

    /** Le livreur dont les liens de vente sont utilisés (un seul par test). */
    private function vendeur(): User
    {
        return $this->vendeur ??= $this->livreur('+2250759028545', 'Jean', 'Marc');
    }

    private function livreur(string $telephone, string $prenom = 'Paul', string $nom = 'Koffi'): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR, 'prenom' => $prenom, 'nom' => $nom, 'telephone' => $telephone]);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id]);

        return $user;
    }

    private function corps(?Produit $produit = null, array $surcharge = []): array
    {
        $produit ??= $this->creerProduitPhysique(['quantite_stock' => 5, 'prix' => 100000, 'commission_revente' => 15000]);
        $lien = LienAffilie::create(['produit_id' => $produit->id, 'livreur_id' => $this->vendeur()->id, 'code' => 'lien'.$produit->id]);

        return array_merge([
            'origine' => 'lien', 'code' => $lien->code, 'quantite' => 1, 'nom' => 'Kouassi', 'prenom' => 'Awa',
            'telephone' => '07 11 22 33 44', 'localite_id' => Localite::where('nom', 'Cocody')->value('id'),
        ], $surcharge);
    }

    public function test_la_page_de_retour_lit_le_statut_reel_de_la_confirmation(): void
    {
        $this->ouvrirConfirmation($this->corps())->assertCreated();
        $acompte = AcompteConfirmation::firstOrFail();

        // Adresse de retour de Wave : sur la page de l'acheteur, avec le jeton (jamais l'id).
        $this->assertSame("https://commande.exemple.test/boutique/confirmation/{$acompte->token}", app(\App\Services\AcompteConfirmationService::class)->urlRetour($acompte));
        Http::assertSent(fn ($requete) => $requete['amount'] === '200'
            && $requete['currency'] === 'XOF'
            && $requete['client_reference'] === "acompte-{$acompte->id}"
            && str_ends_with($requete['success_url'], "/boutique/confirmation/{$acompte->token}")
            && $requete['error_url'] === $requete['success_url']);

        $avant = $this->getJson("/api/v1/public/confirmations/{$acompte->token}")->assertOk();
        $this->assertSame(STATUT_PAIEMENT_EN_ATTENTE, $avant->json('data.statut'));
        $this->assertNotNull($avant->json('data.wave_launch_url'));
        $this->assertNull($avant->json('data.reference'));

        $this->webhookWave($acompte->wave_checkout_session_id)->assertOk();

        $apres = $this->getJson("/api/v1/public/confirmations/{$acompte->token}")->assertOk();
        $this->assertSame(STATUT_PAIEMENT_CONFIRME, $apres->json('data.statut'));
        $this->assertNull($apres->json('data.wave_launch_url'));
        $this->assertSame(Commande::firstOrFail()->id, $apres->json('data.reference'));
        $this->assertFalse($apres->json('data.anomalie'));

        $this->getJson('/api/v1/public/confirmations/inconnu')->assertNotFound();
    }

    public function test_un_webhook_rejoue_ne_cree_qu_une_commande_et_ne_retient_le_stock_qu_une_fois(): void
    {
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5, 'prix' => 100000, 'commission_revente' => 15000]);
        $this->ouvrirConfirmation($this->corps($produit))->assertCreated();

        $this->webhookWave('cos_1')->assertOk();
        $this->webhookWave('cos_1')->assertOk();

        $this->assertSame(1, Commande::count());
        $this->assertSame(4, $produit->fresh()->quantite_stock);
    }

    public function test_un_paiement_echoue_ne_cree_aucune_commande(): void
    {
        $this->ouvrirConfirmation($this->corps())->assertCreated();

        $this->webhookWave('cos_1', 'checkout.session.payment_failed')->assertOk();

        $this->assertSame(STATUT_PAIEMENT_ECHOUE, AcompteConfirmation::firstOrFail()->statut);
        $this->assertSame(0, Commande::count());
        $this->assertNull($this->getJson('/api/v1/public/confirmations/'.AcompteConfirmation::first()->token)->json('data.wave_launch_url'));
    }

    public function test_un_webhook_non_signe_est_rejete(): void
    {
        $this->ouvrirConfirmation($this->corps())->assertCreated();

        $corps = json_encode(['type' => 'checkout.session.completed', 'data' => ['id' => 'cos_1']]);
        $this->call('POST', '/api/v1/webhooks/wave', [], [], [], ['HTTP_Wave-Signature' => 't='.time().',v1=faux', 'CONTENT_TYPE' => 'application/json'], $corps)->assertUnauthorized();

        $this->assertSame(0, Commande::count());
        $this->assertSame(STATUT_PAIEMENT_EN_ATTENTE, AcompteConfirmation::firstOrFail()->statut);
    }

    public function test_wave_non_configure_n_ouvre_aucune_confirmation(): void
    {
        config(['services.wave.api_key' => null]);

        $this->ouvrirConfirmation($this->corps())->assertUnprocessable();

        $this->assertSame(0, AcompteConfirmation::count());
    }

    public function test_l_ancienne_route_de_commande_sans_confirmation_n_existe_plus(): void
    {
        $this->postJson('/api/v1/public/commandes', $this->corps())->assertNotFound();
    }

    public function test_stock_epuise_entre_l_ouverture_et_le_paiement_devient_une_anomalie_pour_l_admin(): void
    {
        $produit = $this->creerProduitPhysique(['quantite_stock' => 1, 'prix' => 100000, 'commission_revente' => 15000]);
        $this->ouvrirConfirmation($this->corps($produit))->assertCreated();
        $produit->update(['quantite_stock' => 0]); // vendu à quelqu'un d'autre pendant le paiement

        $this->webhookWave('cos_1')->assertOk();

        $acompte = AcompteConfirmation::firstOrFail();
        $this->assertTrue($acompte->estAnomalie());
        $this->assertSame(0, Commande::count());

        $lecture = $this->getJson("/api/v1/public/confirmations/{$acompte->token}");
        $this->assertTrue($lecture->json('data.anomalie'));

        $admin = $this->actingAs($this->creerAdmin());
        $liste = $admin->getJson('/api/v1/admin/confirmations?statut=anomalie')->assertOk();
        $this->assertSame(1, $liste->json('data.stats.anomalie'));
        $this->assertSame(200, $liste->json('data.stats.encaisse'));
        $ligne = $liste->json('data.confirmations.data.0');
        $this->assertTrue($ligne['anomalie']);
        $this->assertStringContainsString('stock', $ligne['erreur']);
        $this->assertSame('Awa Kouassi', $ligne['client']);
        $this->assertSame('Jean Marc', $ligne['vendeur']);
    }

    public function test_l_admin_suit_les_confirmations_par_statut(): void
    {
        $this->passerCommandePublique($this->corps());                       // payée, commande créée
        $this->ouvrirConfirmation($this->corps(null, ['nom' => 'Attend']))->assertCreated();  // en attente de Wave
        $this->ouvrirConfirmation($this->corps(null, ['nom' => 'Echoue']))->assertCreated();
        $this->webhookWave('cos_3', 'checkout.session.payment_failed')->assertOk();

        $admin = $this->actingAs($this->creerAdmin());
        $tout = $admin->getJson('/api/v1/admin/confirmations')->assertOk();

        $this->assertEquals(['en_attente' => 1, 'confirme' => 1, 'echoue' => 1, 'anomalie' => 0, 'encaisse' => 200], $tout->json('data.stats'));
        $this->assertCount(3, $tout->json('data.confirmations.data'));
        $this->assertCount(1, $admin->getJson('/api/v1/admin/confirmations?statut=en_attente')->json('data.confirmations.data'));
        $this->assertCount(1, $admin->getJson('/api/v1/admin/confirmations?q=Echoue')->json('data.confirmations.data'));
        $this->assertNotNull($admin->getJson('/api/v1/admin/confirmations?statut=confirme')->json('data.confirmations.data.0.commande_id'));
        $admin->getJson('/api/v1/admin/confirmations?statut=zzz')->assertUnprocessable();
    }

    public function test_le_suivi_des_confirmations_est_reserve_a_l_admin(): void
    {
        $this->actingAs($this->creerCoordinateur())->getJson('/api/v1/admin/confirmations')->assertForbidden();
    }

    public function test_la_fiche_admin_montre_la_confirmation_et_le_reliquat(): void
    {
        $commande = $this->passerCommandePublique($this->corps(null, ['quantite' => 2]));

        $fiche = $this->actingAs($this->creerAdmin())->getJson("/api/v1/admin/commandes/{$commande->id}")->assertOk();

        $this->assertSame(200, $fiche->json('data.acompte.montant'));
        $this->assertSame(STATUT_PAIEMENT_CONFIRME, $fiche->json('data.acompte.statut'));
        $this->assertEquals(201800, $fiche->json('data.reliquat'));
    }

    public function test_le_livreur_n_encaisse_que_le_reliquat_du_client(): void
    {
        $commande = $this->passerCommandePublique($this->corps(null, ['quantite' => 2]));   // 200 000 + 2 000 de livraison
        $livreur = $this->livreur('+2250700000001');
        $commande->livraison->update(['livreur_id' => $livreur->id, 'statut_livraison' => STATUT_LIVRAISON_EN_COURS]);

        // La mission montre au livreur ce que le client doit encore, pas le total.
        $mission = $this->actingAs($livreur)->getJson("/api/v1/livraisons/{$commande->livraison->id}")->assertOk();
        $this->assertEquals(201800, $mission->json('data.montant_total_a_payer'));
        $this->assertEquals(200, $mission->json('data.acompte_confirmation_paye'));

        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$commande->id}/paiement", ['mode_paiement' => MODE_PAIEMENT_ESPECES])->assertCreated();
        $this->assertEquals(201800, Paiement::where('commande_id', $commande->id)->firstOrFail()->montant);
    }

    public function test_le_paiement_wave_a_la_livraison_porte_sur_le_reliquat(): void
    {
        $commande = $this->passerCommandePublique($this->corps(null, ['quantite' => 2]));
        $livreur = $this->livreur('+2250700000001');
        $commande->livraison->update(['livreur_id' => $livreur->id, 'statut_livraison' => STATUT_LIVRAISON_EN_COURS]);

        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$commande->id}/paiement/wave")->assertCreated();

        Http::assertSent(fn ($requete) => str_ends_with($requete->url(), '/checkout/sessions') && $requete['amount'] === '201800' && $requete['client_reference'] === (string) $commande->id);
    }

    public function test_une_commande_sans_confirmation_reste_payee_en_totalite_a_la_livraison(): void
    {
        $commande = $this->passerCommandePublique($this->corps());
        AcompteConfirmation::where('commande_id', $commande->id)->delete();

        $this->assertEquals($commande->fresh()->montantNet(), $commande->fresh()->reliquat());
    }
}
