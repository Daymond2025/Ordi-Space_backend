<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\User;
use App\Models\VenteBoutique;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * GET /boutique/ventes — écran "Centre des ventes" du livreur. Les ventes
 * sont fabriquées par la commande boutique:ventes-demo (vraies commandes
 * rattachées au livreur), ce qui teste aussi cette commande de bout en bout.
 */
class BoutiqueVentesTest extends TestCase
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
        Livreur::create(['user_id' => $user->id]);

        return $user;
    }

    /** Livreur vendeur + 6 ventes (3 livrées, 1 en cours, 1 annulée, 1 en attente) sur un produit à commission. */
    private function livreurAvecVentes(): array
    {
        $this->creerCoordinateur();
        $livreur = $this->creerLivreur();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 10, 'commission_revente' => 15000]);

        $code = Artisan::call('boutique:ventes-demo', ['email' => $livreur->email, '--produit' => $produit->id]);
        $this->assertSame(0, $code, Artisan::output());

        return [$livreur, $produit];
    }

    public function test_le_livreur_voit_ses_ventes_avec_les_compteurs(): void
    {
        [$livreur] = $this->livreurAvecVentes();

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/boutique/ventes');

        $reponse->assertOk();
        $this->assertSame(6, $reponse->json('data.stats.commandes'));
        $this->assertSame(1, $reponse->json('data.stats.produits'));
        $this->assertSame(
            ['en_attente' => 1, 'en_cours' => 1, 'livree' => 3, 'annulee' => 1],
            $reponse->json('data.stats.par_statut')
        );
        $this->assertCount(6, $reponse->json('data.ventes.data'));

        $vente = $reponse->json('data.ventes.data.0');
        $this->assertSame(15000, $vente['commission']);
        foreach (['nom_produit', 'image', 'specs', 'client', 'date', 'prix_vente', 'source', 'statut'] as $cle) {
            $this->assertArrayHasKey($cle, $vente);
        }
    }

    public function test_filtrer_par_statut_ou_source_ne_change_pas_les_compteurs(): void
    {
        [$livreur] = $this->livreurAvecVentes();

        $enCours = $this->actingAs($livreur)->getJson('/api/v1/boutique/ventes?statut=en_cours');
        $enCours->assertOk();
        $this->assertCount(1, $enCours->json('data.ventes.data'));
        $this->assertSame('en_cours', $enCours->json('data.ventes.data.0.statut'));
        $this->assertSame(6, $enCours->json('data.stats.commandes'));

        $whatsapp = $this->actingAs($livreur)->getJson('/api/v1/boutique/ventes?source=whatsapp');
        $this->assertCount(3, $whatsapp->json('data.ventes.data'));

        $this->actingAs($livreur)->getJson('/api/v1/boutique/ventes?statut=inconnu')->assertUnprocessable();
    }

    public function test_un_livreur_ne_voit_que_ses_propres_ventes(): void
    {
        $this->livreurAvecVentes();
        $autreLivreur = $this->creerLivreur();

        $reponse = $this->actingAs($autreLivreur)->getJson('/api/v1/boutique/ventes');

        $reponse->assertOk();
        $this->assertSame(0, $reponse->json('data.stats.commandes'));
        $this->assertSame([], $reponse->json('data.ventes.data'));
    }

    public function test_un_non_livreur_est_refuse(): void
    {
        $this->actingAs($this->creerClient())->getJson('/api/v1/boutique/ventes')->assertForbidden();
    }

    public function test_la_commission_est_figee_a_la_vente(): void
    {
        [$livreur, $produit] = $this->livreurAvecVentes();

        $produit->update(['commission_revente' => 99999]);

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/boutique/ventes');
        $this->assertSame(15000, $reponse->json('data.ventes.data.0.commission'));
    }

    public function test_les_ventes_reposent_sur_de_vraies_commandes_et_le_stock(): void
    {
        [$livreur, $produit] = $this->livreurAvecVentes();

        $this->assertSame(6, VenteBoutique::where('livreur_id', $livreur->id)->count());
        // 6 commandes passées − 1 annulée (stock restitué) = 5 unités sorties.
        $this->assertSame(5, $produit->fresh()->quantite_stock);
        $this->assertDatabaseHas('commandes', ['statut_commande' => STATUT_COMMANDE_LIVREE]);
    }

    public function test_relancer_la_commande_ne_cree_aucun_doublon(): void
    {
        [$livreur, $produit] = $this->livreurAvecVentes();

        $code = Artisan::call('boutique:ventes-demo', ['email' => $livreur->email, '--produit' => $produit->id]);

        $this->assertSame(0, $code);
        $this->assertSame(6, VenteBoutique::where('livreur_id', $livreur->id)->count());
        $this->assertSame(5, $produit->fresh()->quantite_stock);
    }

    public function test_le_livreur_voit_ses_liens_avec_visites_et_commandes(): void
    {
        [$livreur, $produit] = $this->livreurAvecVentes();

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/boutique/liens');

        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data'));
        $lien = $reponse->json('data.0');
        $this->assertSame($produit->id, $lien['produit_id']);
        $this->assertSame(51, $lien['vues']);
        // 5 commandes ont transité par le lien ; la commande manuelle n'en fait pas partie.
        $this->assertSame(5, $lien['commandes']);
        $this->assertSame(['en_attente' => 1, 'en_cours' => 1, 'livree' => 2, 'annulee' => 1], $lien['par_statut']);
        $this->assertTrue($lien['actif']);
        $this->assertStringEndsWith("/boutique/produit/{$lien['code']}", $lien['url']);
    }

    public function test_un_lien_sans_activite_apparait_avec_des_compteurs_a_zero(): void
    {
        $livreur = $this->creerLivreur();
        $produit = $this->creerProduitPhysique();
        $this->actingAs($livreur)->postJson("/api/v1/boutique/produits/{$produit->id}/lien")->assertOk();

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/boutique/liens');

        $this->assertSame(0, $reponse->json('data.0.vues'));
        $this->assertSame(0, $reponse->json('data.0.commandes'));
        $this->assertNotNull($reponse->json('data.0.derniere_activite'));
    }

    public function test_les_liens_d_un_autre_livreur_sont_invisibles_et_un_non_livreur_est_refuse(): void
    {
        $this->livreurAvecVentes();

        $this->actingAs($this->creerLivreur())->getJson('/api/v1/boutique/liens')->assertOk()->assertJsonPath('data', []);
        $this->actingAs($this->creerClient())->getJson('/api/v1/boutique/liens')->assertForbidden();
    }

    public function test_la_page_d_un_lien_donne_ses_commandes_et_le_gain_des_commandes_validees(): void
    {
        [$livreur] = $this->livreurAvecVentes();
        $lienId = $this->actingAs($livreur)->getJson('/api/v1/boutique/liens')->json('data.0.id');

        $reponse = $this->actingAs($livreur)->getJson("/api/v1/boutique/liens/{$lienId}");

        $reponse->assertOk();
        $this->assertSame($lienId, $reponse->json('data.lien.id'));
        $this->assertSame(5, $reponse->json('data.lien.commandes'));
        $this->assertCount(5, $reponse->json('data.ventes.data'));
        // Gain = 1 en cours + 2 livrées validées (3 × 15 000) ; l'en attente et l'annulée ne rapportent rien.
        $this->assertSame(45000, $reponse->json('data.gain_total'));
        // La commande manuelle (sans lien) n'apparaît pas dans la page du lien.
        $this->assertNotContains('manuelle', array_column($reponse->json('data.ventes.data'), 'source'));
    }

    public function test_la_page_d_un_lien_est_reservee_a_son_proprietaire(): void
    {
        [$livreur] = $this->livreurAvecVentes();
        $lienId = $this->actingAs($livreur)->getJson('/api/v1/boutique/liens')->json('data.0.id');

        $this->actingAs($this->creerLivreur())->getJson("/api/v1/boutique/liens/{$lienId}")->assertForbidden();
        $this->actingAs($this->creerClient())->getJson("/api/v1/boutique/liens/{$lienId}")->assertForbidden();
        $this->actingAs($livreur)->getJson('/api/v1/boutique/liens/999999')->assertNotFound();
    }

    public function test_le_profil_boutique_agrege_les_totaux_le_lien_et_l_affiche(): void
    {
        [$livreur, $produit] = $this->livreurAvecVentes();

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/boutique/profil');

        $reponse->assertOk();
        $this->assertSame($livreur->nom, $reponse->json('data.livreur.nom'));
        // 3 commandes livrées (manuelle, QR, lien), 1 annulée ; commission = 1 en cours + 3 livrées.
        $this->assertSame(3, $reponse->json('data.stats.produits_vendus'));
        $this->assertSame(3, $reponse->json('data.stats.commandes_livrees'));
        $this->assertSame(1, $reponse->json('data.stats.commandes_annulees'));
        $this->assertSame(60000, $reponse->json('data.stats.commission_totale'));
        // Clics = vitrine (24) + visites du lien par produit (51) ; livrées du canal "lien" = 1.
        $this->assertSame(75, $reponse->json('data.lien.clics'));
        $this->assertSame(1, $reponse->json('data.lien.livrees'));
        $this->assertSame(15000, $reponse->json('data.lien.commission_min'));
        $this->assertSame(15000, $reponse->json('data.lien.commission_max'));
        $this->assertSame(17, $reponse->json('data.affiche.scans'));
        $this->assertSame(1, $reponse->json('data.affiche.livrees'));
        $this->assertStringEndsWith('?src=qr', $reponse->json('data.affiche.url_qr'));
        $this->assertSame($reponse->json('data.lien.url').'?src=qr', $reponse->json('data.affiche.url_qr'));
    }

    public function test_la_vitrine_est_creee_a_la_premiere_ouverture_et_reste_la_meme(): void
    {
        $livreur = $this->creerLivreur();

        $premiere = $this->actingAs($livreur)->getJson('/api/v1/boutique/profil');
        $seconde = $this->actingAs($livreur)->getJson('/api/v1/boutique/profil');

        $this->assertSame($premiere->json('data.lien.url'), $seconde->json('data.lien.url'));
        $this->assertDatabaseCount('vitrines', 1);
        // Compteurs à zéro (jamais null) et pas de fourchette de commission sans produit à revendre.
        $this->assertSame(0, $premiere->json('data.lien.clics'));
        $this->assertSame(0, $premiere->json('data.affiche.scans'));
        $this->assertSame(0, $premiere->json('data.stats.commission_totale'));
        $this->assertNull($premiere->json('data.lien.commission_min'));
    }

    public function test_le_profil_boutique_est_reserve_aux_livreurs(): void
    {
        $this->actingAs($this->creerClient())->getJson('/api/v1/boutique/profil')->assertForbidden();
    }
}
