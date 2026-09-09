<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Livreur;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class AssignerLivreurTest extends TestCase
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

    private function creerCommande(User $commercial, int $quantiteStock = 5): Commande
    {
        $produit = $this->creerProduitPhysique(['quantite_stock' => $quantiteStock]);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        return Commande::findOrFail($reponse->json('data.id'));
    }

    public function test_le_coordinateur_assigne_depuis_validee_ou_en_preparation(): void
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur();

        $commande = $this->creerCommande($commercial);
        $commande->update(['statut_commande' => STATUT_COMMANDE_VALIDEE]);

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/assigner-livreur", [
            'livreur_id' => $livreur->id,
        ]);

        $reponse->assertOk();
        $this->assertDatabaseHas('livraisons', [
            'commande_id' => $commande->id,
            'livreur_id' => $livreur->id,
            // 'assignee', pas 'en_cours' directement : le livreur doit
            // accepter la mission (LivraisonController::accepter()) avant
            // qu'elle ne devienne active.
            'statut_livraison' => STATUT_LIVRAISON_ASSIGNEE,
        ]);
        $this->assertDatabaseHas('commandes', ['id' => $commande->id, 'statut_commande' => STATUT_COMMANDE_EN_LIVRAISON]);
        $this->assertDatabaseHas('journal_audit', ['commande_id' => $commande->id]);
    }

    /**
     * Liberté totale de statut (Espace Coordinateur, écran détail commande) :
     * l'assignation à un livreur doit fonctionner depuis n'importe quel
     * statut source — plus de restriction validee/en_preparation/en_livraison.
     */
    public function test_l_assignation_fonctionne_aussi_depuis_en_attente(): void
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur();

        $commande = $this->creerCommande($commercial); // reste en_attente

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/assigner-livreur", [
            'livreur_id' => $livreur->id,
        ]);

        $reponse->assertOk();
        $this->assertDatabaseHas('livraisons', ['commande_id' => $commande->id, 'livreur_id' => $livreur->id]);
        $this->assertDatabaseHas('commandes', ['id' => $commande->id, 'statut_commande' => STATUT_COMMANDE_EN_LIVRAISON]);
        $this->assertDatabaseHas('journal_audit', ['commande_id' => $commande->id]);
    }

    public function test_rejete_pour_une_commande_100_pourcent_numerique(): void
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur();

        $produitNumerique = $this->creerProduitPhysique(['nom_produit' => 'Licence', 'type_livraison' => 'numerique']);
        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $this->creerClient()->id,
            'lignes' => [['produit_id' => $produitNumerique->id, 'quantite' => 1]],
        ]);
        $commande = Commande::findOrFail($reponse->json('data.id'));
        $commande->update(['statut_commande' => STATUT_COMMANDE_VALIDEE]);

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/assigner-livreur", [
            'livreur_id' => $livreur->id,
        ])->assertStatus(422);
    }

    public function test_un_commercial_ou_fournisseur_ne_peut_pas_assigner(): void
    {
        $commercial = $this->creerCommercial();
        $livreur = $this->creerLivreur();
        $commande = $this->creerCommande($commercial);
        $commande->update(['statut_commande' => STATUT_COMMANDE_VALIDEE]);

        $fournisseurId = $commande->lignes()->first()->produit->fournisseur_id;
        $fournisseur = User::findOrFail($fournisseurId);

        $this->actingAs($commercial)->postJson("/api/v1/commandes/{$commande->id}/assigner-livreur", [
            'livreur_id' => $livreur->id,
        ])->assertForbidden();

        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/assigner-livreur", [
            'livreur_id' => $livreur->id,
        ])->assertForbidden();
    }

    public function test_la_reassignation_ecrase_l_ancien_livreur(): void
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $premierLivreur = $this->creerLivreur();
        $secondLivreur = $this->creerLivreur();

        $commande = $this->creerCommande($commercial);
        $commande->update(['statut_commande' => STATUT_COMMANDE_VALIDEE]);

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/assigner-livreur", [
            'livreur_id' => $premierLivreur->id,
        ])->assertOk();

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/assigner-livreur", [
            'livreur_id' => $secondLivreur->id,
        ])->assertOk();

        $this->assertDatabaseHas('livraisons', ['commande_id' => $commande->id, 'livreur_id' => $secondLivreur->id]);
    }
}
