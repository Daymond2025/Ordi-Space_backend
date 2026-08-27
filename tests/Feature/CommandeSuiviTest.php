<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Livraison;
use App\Models\Livreur;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class CommandeSuiviTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_la_timeline_suit_le_parcours_complet_avec_acteurs(): void
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5]);
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponseCreation = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commande = Commande::findOrFail($reponseCreation->json('data.id'));

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();
        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/preparee")->assertOk();

        $livreurUser = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $livreurUser->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $livreurUser->id]);
        $livraison = Livraison::where('commande_id', $commande->id)->firstOrFail();

        $this->actingAs($livreurUser)->postJson("/api/v1/livraisons/{$livraison->id}/affecter")->assertOk();
        $this->actingAs($livreurUser)->postJson("/api/v1/livraisons/{$livraison->id}/livrer", [
            'preuve_livraison' => 'Remis en main propre',
        ])->assertOk();

        $suivi = $this->actingAs($coordinateur)->getJson("/api/v1/commandes/{$commande->id}/suivi");

        $suivi->assertOk();
        $lignes = $suivi->json('data');
        $this->assertCount(5, $lignes); // créée, validée, préparée, prise en charge, livrée

        foreach ($lignes as $ligne) {
            $this->assertNotNull($ligne['acteur_id']);
        }

        // Ordre chronologique : la création est toujours en premier.
        $this->assertStringContainsString('passé une commande', $lignes[0]['details']);
    }

    public function test_aucune_fuite_entre_deux_commandes_distinctes(): void
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5]);

        $creerCommande = function () use ($commercial, $produit) {
            $client = $this->creerClient();
            $adresse = $this->creerAdresseAvecLocalite($client);
            $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
                'client_id' => $client->id, 'adresse_id' => $adresse->id,
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            ]);

            return Commande::findOrFail($reponse->json('data.id'));
        };

        $commandeA = $creerCommande();
        $commandeB = $creerCommande();

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commandeA->id}/valider")->assertOk();

        $suiviB = $this->actingAs($coordinateur)->getJson("/api/v1/commandes/{$commandeB->id}/suivi");
        $suiviB->assertOk();

        $actions = collect($suiviB->json('data'))->pluck('details');
        $this->assertTrue($actions->every(fn ($details) => str_contains($details, (string) $commandeB->id)));
    }
}
