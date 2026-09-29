<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Livreur;
use App\Models\User;
use App\Services\PushNotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * "Assigner une nouvelle mission" (feuille détail livreur, app Fournisseur) —
 * GET /fournisseur/moi/livraisons-disponibles (vivier scopé) et
 * POST /commandes/{commande}/assigner-livreur-fournisseur (choix manuel d'un
 * livreur précis, borné au vivier de SES propres commandes uniquement).
 */
class AssignerLivreurParFournisseurTest extends TestCase
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
        Livreur::create(['user_id' => $user->id, 'disponible' => true]);

        return $user;
    }

    /** Commande dans le vivier (EN_ATTENTE_LIVREUR, sans livreur) — même montage que RechercheLivreurTest. */
    private function creerCommandeEnVivier(): array
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commande = Commande::findOrFail($reponse->json('data.id'));

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();
        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/preparee")->assertOk();

        return [$commande->fresh(), $fournisseur];
    }

    public function test_le_vivier_ne_montre_que_les_commandes_de_ce_fournisseur(): void
    {
        [$commandeA, $fournisseurA] = $this->creerCommandeEnVivier();
        [$commandeB] = $this->creerCommandeEnVivier();

        $reponse = $this->actingAs($fournisseurA)->getJson('/api/v1/fournisseur/moi/livraisons-disponibles');

        $reponse->assertOk();
        $livraisons = $reponse->json('data');
        $this->assertCount(1, $livraisons);
        $this->assertSame($commandeA->id, $livraisons[0]['commande_id']);
        $this->assertNotSame($commandeB->id, $livraisons[0]['commande_id']);
    }

    public function test_le_fournisseur_assigne_un_livreur_precis_et_il_est_notifie(): void
    {
        [$commande, $fournisseur] = $this->creerCommandeEnVivier();
        $livreur = $this->creerLivreur();

        $this->mock(PushNotificationService::class, function ($mock) {
            $mock->shouldReceive('envoyer')->once()
                ->with(\Mockery::type(User::class), 'Nouvelle mission assignée', \Mockery::type('string'), \Mockery::type('array'));
        });

        $reponse = $this->actingAs($fournisseur)->postJson(
            "/api/v1/commandes/{$commande->id}/assigner-livreur-fournisseur",
            ['livreur_id' => $livreur->id]
        );

        $reponse->assertOk();
        $commande->refresh();
        $this->assertSame(STATUT_COMMANDE_EN_LIVRAISON, $commande->statut_commande);
        $this->assertSame($livreur->id, $commande->livraison->livreur_id);
        $this->assertSame(STATUT_LIVRAISON_ASSIGNEE, $commande->livraison->statut_livraison);
    }

    public function test_impossible_d_assigner_une_commande_deja_prise(): void
    {
        [$commande, $fournisseur] = $this->creerCommandeEnVivier();
        $livreurA = $this->creerLivreur();
        $livreurB = $this->creerLivreur();

        $this->actingAs($fournisseur)->postJson(
            "/api/v1/commandes/{$commande->id}/assigner-livreur-fournisseur",
            ['livreur_id' => $livreurA->id]
        )->assertOk();

        $this->actingAs($fournisseur)->postJson(
            "/api/v1/commandes/{$commande->id}/assigner-livreur-fournisseur",
            ['livreur_id' => $livreurB->id]
        )->assertUnprocessable();
    }

    public function test_un_fournisseur_ne_peut_pas_assigner_la_commande_d_un_autre(): void
    {
        [$commande] = $this->creerCommandeEnVivier();
        $autreFournisseur = $this->creerFournisseur();
        $livreur = $this->creerLivreur();

        $this->actingAs($autreFournisseur)->postJson(
            "/api/v1/commandes/{$commande->id}/assigner-livreur-fournisseur",
            ['livreur_id' => $livreur->id]
        )->assertForbidden();
    }
}
