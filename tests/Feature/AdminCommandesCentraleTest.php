<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\Localite;
use App\Models\User;
use App\Models\Vitrine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\Feature\Concerns\PasseCommandePublique;
use Tests\TestCase;

/**
 * GET /admin/commandes — l'onglet "Commandes" de l'Admin centralise toutes les
 * commandes, qu'elles viennent de l'app, d'un commercial ou d'un livreur
 * (commande manuelle, lien, vitrine, QR).
 */
class AdminCommandesCentraleTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi, PasseCommandePublique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->creerAgentIa();
    }

    private function creerLivreur(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR, 'prenom' => 'Jean', 'nom' => 'Marc']);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id]);

        return $user;
    }

    /** 6 commandes boutique (démo) + 1 commande directe livrée. */
    private function plateformeAvecCommandes(): array
    {
        $this->creerCoordinateur();
        $livreur = $this->creerLivreur();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 10, 'commission_revente' => 15000, 'nom_produit' => 'Laptop Boutique']);
        $this->assertSame(0, Artisan::call('boutique:ventes-demo', ['email' => $livreur->email, '--produit' => $produit->id]), Artisan::output());

        $client = $this->creerClient(['prenom' => 'Direct', 'nom' => 'Acheteur', 'telephone' => '+2250100000000']);
        $this->creerAchatLivre($client, $this->creerProduitPhysique(['nom_produit' => 'Souris Directe']));

        return [$livreur, $produit];
    }

    public function test_l_admin_voit_toutes_les_commandes_avec_leur_origine(): void
    {
        [$livreur] = $this->plateformeAvecCommandes();

        $reponse = $this->actingAs($this->creerAdmin())->getJson('/api/v1/admin/commandes');

        $reponse->assertOk();
        $lignes = $reponse->json('data.commandes.data');
        $this->assertCount(7, $lignes);
        $this->assertSame(7, $reponse->json('data.stats.total'));
        $this->assertSame(1, $reponse->json('data.stats.par_statut.en_attente'));
        $this->assertSame(4, $reponse->json('data.stats.par_statut.livree'));
        $this->assertSame(1, $reponse->json('data.stats.par_statut.annulee'));

        $this->assertEquals(['boutique' => 6, 'directe' => 1], array_count_values(array_column($lignes, 'origine')));

        $boutique = collect($lignes)->firstWhere('origine', 'boutique');
        $this->assertSame($livreur->id, $boutique['vendeur_id']);
        $this->assertContains($boutique['source'], SOURCES_VENTE_BOUTIQUE);
        $directe = collect($lignes)->firstWhere('origine', 'directe');
        $this->assertNull($directe['vendeur']);
        $this->assertSame('Souris Directe', $directe['nom_produit']);
    }

    public function test_les_filtres_statut_origine_et_recherche(): void
    {
        $this->plateformeAvecCommandes();
        $admin = $this->creerAdmin();
        $lister = fn (string $query) => $this->actingAs($admin)->getJson("/api/v1/admin/commandes?{$query}");

        $this->assertCount(1, $lister('statut=en_attente')->json('data.commandes.data'));
        $this->assertCount(6, $lister('origine=boutique')->json('data.commandes.data'));
        $this->assertCount(1, $lister('origine=directe')->json('data.commandes.data'));
        $this->assertCount(1, $lister('q=Souris')->json('data.commandes.data'));
        $this->assertCount(6, $lister('q=Laptop')->json('data.commandes.data'));
        $this->assertCount(1, $lister('q=Acheteur')->json('data.commandes.data'));
        $this->assertCount(1, $lister('q=0100000000')->json('data.commandes.data'));
        $this->assertCount(0, $lister('q=introuvable')->json('data.commandes.data'));

        // Un nombre cherche le numéro de commande ET les téléphones : la commande visée fait partie des résultats.
        $premiere = $lister('')->json('data.commandes.data.0.id');
        $this->assertContains($premiere, array_column($lister("q={$premiere}")->json('data.commandes.data'), 'id'));

        // Les compteurs ignorent les filtres.
        $this->assertSame(7, $lister('statut=en_attente')->json('data.stats.total'));
        $lister('statut=zzz')->assertUnprocessable();
        $lister('origine=zzz')->assertUnprocessable();
    }

    public function test_une_commande_de_la_page_acheteur_arrive_dans_l_onglet_commandes(): void
    {
        $livreur = $this->creerLivreur();
        $vitrine = Vitrine::pour($livreur);
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5, 'commission_revente' => 15000, 'nom_produit' => 'Laptop Page Acheteur']);

        $this->configurerWavePublic();
        $this->fauxWave();
        $this->passerCommandePublique([
            'origine' => 'vitrine', 'code' => $vitrine->code, 'produit_id' => $produit->id, 'src' => 'qr', 'quantite' => 1,
            'nom' => 'Traoré', 'prenom' => 'Fanta', 'telephone' => '0700112233',
            'localite_id' => Localite::where('nom', 'Cocody')->value('id'), 'adresse' => 'Angré 8e tranche',
        ]);

        $ligne = $this->actingAs($this->creerAdmin())->getJson('/api/v1/admin/commandes?q=Fanta')->json('data.commandes.data.0');

        $this->assertSame('boutique', $ligne['origine']);
        $this->assertSame('qr', $ligne['source']);
        $this->assertSame('Jean Marc', $ligne['vendeur']);
        $this->assertSame('Laptop Page Acheteur', $ligne['nom_produit']);
        $this->assertSame(STATUT_COMMANDE_EN_ATTENTE, $ligne['statut_commande']);
        $this->assertSame('Fanta Traoré', $ligne['client']);
        // La confirmation payée en ligne est visible, et déduite du reliquat du client.
        $this->assertSame(200, $ligne['confirmation']['montant']);
        $this->assertSame(STATUT_PAIEMENT_CONFIRME, $ligne['confirmation']['statut']);
        $this->assertEquals($ligne['total_a_payer'] - 200, $ligne['reliquat']);
    }

    public function test_l_onglet_commandes_est_reserve_a_l_admin(): void
    {
        $this->actingAs($this->creerLivreur())->getJson('/api/v1/admin/commandes')->assertForbidden();
        $this->actingAs($this->creerClient())->getJson('/api/v1/admin/commandes')->assertForbidden();
    }
}
