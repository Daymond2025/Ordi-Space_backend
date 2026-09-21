<?php

namespace Tests\Feature;

use App\Models\DemandeRetrait;
use App\Models\Livreur;
use App\Models\NotificationOrdispace;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Portefeuille de commissions du livreur (Boutique) : soldes recalculés depuis
 * ses ventes, demandes de retrait, et traitement par l'Admin. Les ventes et
 * trois retraits (payé, refusé, en attente) viennent de boutique:ventes-demo.
 *
 * Jeu de données de la commande de démo, pour 15 000 F de commission par vente :
 * 4 ventes validées (gain 60 000), 1 en attente de validation (15 000), 1 annulée ;
 * retraits : 20 000 payé, 30 000 refusé (restitué), 10 000 en attente
 * → disponible = 60 000 − 20 000 − 10 000 = 30 000.
 */
class PortefeuilleCommissionsTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerLivreur(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id, 'type_vehicule' => 'moto']);

        return $user;
    }

    private function livreurAvecVentes(): User
    {
        $this->creerCoordinateur();
        $livreur = $this->creerLivreur();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 10, 'commission_revente' => 15000]);

        $this->assertSame(0, Artisan::call('boutique:ventes-demo', ['email' => $livreur->email, '--produit' => $produit->id]), Artisan::output());

        return $livreur;
    }

    private function disponible(User $livreur): float
    {
        return $this->actingAs($livreur)->getJson('/api/v1/boutique/portefeuille')->json('data.disponible');
    }

    public function test_les_soldes_sont_recalcules_depuis_les_ventes_et_les_retraits(): void
    {
        $livreur = $this->livreurAvecVentes();

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/boutique/portefeuille');

        $reponse->assertOk();
        $this->assertSame(60000, $reponse->json('data.gain_total'));
        $this->assertSame(15000, $reponse->json('data.en_attente_validation'));
        $this->assertSame(10000, $reponse->json('data.retrait_en_cours'));
        $this->assertSame(20000, $reponse->json('data.retire'));
        $this->assertSame(30000, $reponse->json('data.disponible'));
        $this->assertSame(1000, $reponse->json('data.retrait_minimum'));
    }

    public function test_un_livreur_sans_activite_a_un_portefeuille_vide(): void
    {
        $reponse = $this->actingAs($this->creerLivreur())->getJson('/api/v1/boutique/portefeuille');

        $this->assertSame(0, $reponse->json('data.disponible'));
        $this->assertSame(0, $reponse->json('data.gain_total'));
    }

    public function test_la_liste_des_commissions_distingue_acquises_en_attente_et_annulees(): void
    {
        $livreur = $this->livreurAvecVentes();

        $lignes = $this->actingAs($livreur)->getJson('/api/v1/boutique/portefeuille/commissions')->json('data.data');

        $this->assertCount(6, $lignes);
        $comptes = array_count_values(array_column($lignes, 'statut'));
        $this->assertSame(['acquise' => 4, 'en_attente' => 1, 'annulee' => 1], $comptes);
        $this->assertSame(15000, $lignes[0]['montant']);
        $this->assertNotEmpty($lignes[0]['nom_produit']);
    }

    public function test_le_livreur_voit_ses_retraits_du_plus_recent_au_plus_ancien(): void
    {
        $livreur = $this->livreurAvecVentes();

        $retraits = $this->actingAs($livreur)->getJson('/api/v1/boutique/retraits')->json('data.data');

        $this->assertSame(
            [STATUT_RETRAIT_EN_ATTENTE, STATUT_RETRAIT_REFUSE, STATUT_RETRAIT_VALIDE],
            array_column($retraits, 'statut')
        );
        $this->assertSame('OM-260912-48213', $retraits[2]['reference']);
        $this->assertStringContainsString('incorrect', $retraits[1]['remarque']);
    }

    public function test_une_demande_de_retrait_reserve_le_montant(): void
    {
        $livreur = $this->livreurAvecVentes();

        $reponse = $this->actingAs($livreur)->postJson('/api/v1/boutique/retraits', [
            'montant' => 12000, 'operateur' => 'Orange', 'telephone' => '07 58 84 92 81',
        ]);

        $reponse->assertCreated();
        $this->assertSame(STATUT_RETRAIT_EN_ATTENTE, $reponse->json('data.statut'));
        $this->assertSame('+2250758849281', $reponse->json('data.telephone'));
        $this->assertSame(18000.0, $this->disponible($livreur));
    }

    public function test_on_ne_peut_pas_retirer_plus_que_le_disponible_ni_moins_que_le_minimum(): void
    {
        $livreur = $this->livreurAvecVentes();
        $corps = ['operateur' => 'Wave', 'telephone' => '0758849281'];

        $this->actingAs($livreur)->postJson('/api/v1/boutique/retraits', $corps + ['montant' => 30001])
            ->assertUnprocessable()->assertJsonPath('error.fields.montant.0', fn ($m) => is_string($m));
        $this->actingAs($livreur)->postJson('/api/v1/boutique/retraits', $corps + ['montant' => 999])
            ->assertUnprocessable()->assertJsonPath('error.fields.montant.0', fn ($m) => is_string($m));

        $this->assertSame(3, DemandeRetrait::where('user_id', $livreur->id)->count());
    }

    public function test_deux_demandes_successives_ne_peuvent_pas_depasser_ensemble_le_disponible(): void
    {
        $livreur = $this->livreurAvecVentes();
        $corps = ['operateur' => 'Mtn', 'telephone' => '0758849281'];

        $this->actingAs($livreur)->postJson('/api/v1/boutique/retraits', $corps + ['montant' => 20000])->assertCreated();
        $this->actingAs($livreur)->postJson('/api/v1/boutique/retraits', $corps + ['montant' => 10001])->assertUnprocessable();
        $this->actingAs($livreur)->postJson('/api/v1/boutique/retraits', $corps + ['montant' => 10000])->assertCreated();

        $this->assertSame(0.0, $this->disponible($livreur));
    }

    public function test_les_champs_du_retrait_sont_valides(): void
    {
        $livreur = $this->livreurAvecVentes();

        $this->actingAs($livreur)->postJson('/api/v1/boutique/retraits', ['montant' => 5000, 'operateur' => 'Paypal', 'telephone' => '0758849281'])
            ->assertUnprocessable()->assertJsonPath('error.fields.operateur.0', fn ($m) => is_string($m));
        $this->actingAs($livreur)->postJson('/api/v1/boutique/retraits', ['montant' => 5000, 'operateur' => 'Orange', 'telephone' => '123'])
            ->assertUnprocessable()->assertJsonPath('error.fields.telephone.0', fn ($m) => is_string($m));
        $this->actingAs($livreur)->postJson('/api/v1/boutique/retraits', [])->assertUnprocessable();
    }

    public function test_annuler_une_demande_en_attente_restitue_le_montant(): void
    {
        $livreur = $this->livreurAvecVentes();
        $enAttente = DemandeRetrait::where('user_id', $livreur->id)->where('statut', STATUT_RETRAIT_EN_ATTENTE)->firstOrFail();

        $this->actingAs($livreur)->putJson("/api/v1/boutique/retraits/{$enAttente->id}/annuler")->assertOk();

        $this->assertSame(STATUT_RETRAIT_ANNULE, $enAttente->fresh()->statut);
        $this->assertSame(40000.0, $this->disponible($livreur));
    }

    public function test_une_demande_deja_traitee_ou_d_un_autre_ne_s_annule_pas(): void
    {
        $livreur = $this->livreurAvecVentes();
        $payee = DemandeRetrait::where('user_id', $livreur->id)->where('statut', STATUT_RETRAIT_VALIDE)->firstOrFail();

        $this->actingAs($livreur)->putJson("/api/v1/boutique/retraits/{$payee->id}/annuler")->assertUnprocessable();
        $this->actingAs($this->creerLivreur())->putJson("/api/v1/boutique/retraits/{$payee->id}/annuler")->assertForbidden();
    }

    public function test_l_admin_valide_un_retrait_avec_la_reference_et_le_livreur_est_notifie(): void
    {
        $livreur = $this->livreurAvecVentes();
        $admin = $this->creerAdmin();
        $enAttente = DemandeRetrait::where('user_id', $livreur->id)->where('statut', STATUT_RETRAIT_EN_ATTENTE)->firstOrFail();

        $this->actingAs($admin)->postJson("/api/v1/admin/retraits/{$enAttente->id}/valider", [])->assertUnprocessable();

        $this->actingAs($admin)->postJson("/api/v1/admin/retraits/{$enAttente->id}/valider", ['reference' => 'MTN-998877'])->assertOk();

        $retrait = $enAttente->fresh();
        $this->assertSame(STATUT_RETRAIT_VALIDE, $retrait->statut);
        $this->assertSame('MTN-998877', $retrait->reference);
        $this->assertSame($admin->id, $retrait->admin_id);
        $this->assertNotNull($retrait->traite_le);
        // Le montant reste déduit (payé) : le disponible ne bouge pas.
        $this->assertSame(30000.0, $this->disponible($livreur));
        $this->assertTrue(NotificationOrdispace::where('user_id', $livreur->id)->where('type_notification', 'retrait_valide')->exists());

        $this->actingAs($admin)->postJson("/api/v1/admin/retraits/{$enAttente->id}/valider", ['reference' => 'X'])->assertUnprocessable();
    }

    public function test_l_admin_refuse_un_retrait_avec_un_motif_et_le_montant_est_restitue(): void
    {
        $livreur = $this->livreurAvecVentes();
        $admin = $this->creerAdmin();
        $enAttente = DemandeRetrait::where('user_id', $livreur->id)->where('statut', STATUT_RETRAIT_EN_ATTENTE)->firstOrFail();

        $this->actingAs($admin)->postJson("/api/v1/admin/retraits/{$enAttente->id}/refuser", [])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/v1/admin/retraits/{$enAttente->id}/refuser", ['remarque' => 'Numéro invalide'])->assertOk();

        $this->assertSame(STATUT_RETRAIT_REFUSE, $enAttente->fresh()->statut);
        $this->assertSame(40000.0, $this->disponible($livreur));
        $this->assertTrue(NotificationOrdispace::where('user_id', $livreur->id)->where('type_notification', 'retrait_refuse')->exists());
    }

    public function test_l_admin_liste_les_retraits_et_filtre_par_statut(): void
    {
        $this->livreurAvecVentes();

        $tous = $this->actingAs($this->creerAdmin())->getJson('/api/v1/admin/retraits');
        $enAttente = $this->actingAs($this->creerAdmin())->getJson('/api/v1/admin/retraits?statut=en_attente');

        $this->assertCount(3, $tous->json('data.retraits.data'));
        $this->assertCount(1, $enAttente->json('data.retraits.data'));
        $this->assertNotEmpty($enAttente->json('data.retraits.data.0.livreur'));
        // Les totaux ignorent le filtre : la même chose que la liste complète.
        $this->assertSame(['nombre' => 1, 'montant' => 10000], $enAttente->json('data.stats.en_attente'));
        $this->assertSame(['nombre' => 1, 'montant' => 20000], $enAttente->json('data.stats.valide'));
        $this->assertSame(['nombre' => 1, 'montant' => 30000], $enAttente->json('data.stats.refuse'));
        $this->assertSame(['nombre' => 0, 'montant' => 0], $enAttente->json('data.stats.annule'));
        $this->actingAs($this->creerAdmin())->getJson('/api/v1/admin/retraits?statut=zzz')->assertUnprocessable();
    }

    public function test_l_admin_suit_les_commandes_boutique_de_tous_les_livreurs(): void
    {
        $livreur = $this->livreurAvecVentes();
        $admin = $this->creerAdmin();

        $reponse = $this->actingAs($admin)->getJson('/api/v1/admin/boutique/commandes');

        $reponse->assertOk();
        $this->assertCount(6, $reponse->json('data.commandes.data'));
        $this->assertSame(['en_attente' => 1, 'en_cours' => 1, 'livree' => 3, 'annulee' => 1], $reponse->json('data.stats.par_statut'));
        $this->assertSame(15000, $reponse->json('data.stats.commission_a_valider'));
        $this->assertSame(60000, $reponse->json('data.stats.commission_acquise'));

        $ligne = $reponse->json('data.commandes.data.0');
        $this->assertSame($livreur->id, $ligne['livreur_id']);
        foreach (['commande_id', 'livreur', 'client', 'nom_produit', 'prix_vente', 'commission', 'source', 'statut', 'statut_commande', 'date'] as $cle) {
            $this->assertArrayHasKey($cle, $ligne);
        }
    }

    public function test_les_commandes_boutique_se_filtrent_par_statut_source_et_livreur(): void
    {
        $livreur = $this->livreurAvecVentes();
        $admin = $this->creerAdmin();

        $this->assertCount(1, $this->actingAs($admin)->getJson('/api/v1/admin/boutique/commandes?statut=en_attente')->json('data.commandes.data'));
        $this->assertCount(3, $this->actingAs($admin)->getJson('/api/v1/admin/boutique/commandes?source=whatsapp')->json('data.commandes.data'));
        $this->assertCount(6, $this->actingAs($admin)->getJson("/api/v1/admin/boutique/commandes?livreur_id={$livreur->id}")->json('data.commandes.data'));
        $this->assertCount(0, $this->actingAs($admin)->getJson('/api/v1/admin/boutique/commandes?livreur_id=999999')->json('data.commandes.data'));
        $this->actingAs($admin)->getJson('/api/v1/admin/boutique/commandes?statut=zzz')->assertUnprocessable();
    }

    public function test_valider_une_commande_boutique_rend_la_commission_acquise_au_livreur(): void
    {
        $livreur = $this->livreurAvecVentes();
        $admin = $this->creerAdmin();
        $enAttente = $this->actingAs($admin)->getJson('/api/v1/admin/boutique/commandes?statut=en_attente')->json('data.commandes.data.0');

        $this->assertSame(30000.0, $this->disponible($livreur));

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$enAttente['commande_id']}/statut", ['statut_commande' => STATUT_COMMANDE_VALIDEE])->assertOk();

        // +15 000 de commission acquise, donc disponible aussi.
        $this->assertSame(45000.0, $this->disponible($livreur));
        $resume = $this->actingAs($livreur)->getJson('/api/v1/boutique/portefeuille')->json('data');
        $this->assertSame(75000, $resume['gain_total']);
        $this->assertSame(0, $resume['en_attente_validation']);
    }

    public function test_les_commandes_boutique_sont_reservees_a_l_admin(): void
    {
        $livreur = $this->livreurAvecVentes();

        $this->actingAs($livreur)->getJson('/api/v1/admin/boutique/commandes')->assertForbidden();
        $this->actingAs($this->creerClient())->getJson('/api/v1/admin/boutique/commandes')->assertForbidden();
    }

    public function test_seuls_les_livreurs_ont_un_portefeuille_et_seul_l_admin_traite_les_retraits(): void
    {
        $livreur = $this->livreurAvecVentes();
        $retrait = DemandeRetrait::where('user_id', $livreur->id)->where('statut', STATUT_RETRAIT_EN_ATTENTE)->firstOrFail();

        $this->actingAs($this->creerClient())->getJson('/api/v1/boutique/portefeuille')->assertForbidden();
        $this->actingAs($livreur)->getJson('/api/v1/admin/retraits')->assertForbidden();
        $this->actingAs($livreur)->postJson("/api/v1/admin/retraits/{$retrait->id}/valider", ['reference' => 'X'])->assertForbidden();
    }
}
