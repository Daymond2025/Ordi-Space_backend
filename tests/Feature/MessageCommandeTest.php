<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Produit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class MessageCommandeTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommandePour(User $commercial, Produit $produit): Commande
    {
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        return Commande::findOrFail($reponse->json('data.id'));
    }

    public function test_le_fournisseur_concerne_le_commercial_proprietaire_et_le_coordinateur_peuvent_poster(): void
    {
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommandePour($commercial, $produit);

        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/messages", ['contenu' => 'Numéro incorrect'])->assertCreated();
        $this->actingAs($commercial)->postJson("/api/v1/commandes/{$commande->id}/messages", ['contenu' => 'Je recontacte le client'])->assertCreated();
        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/messages", ['contenu' => 'Suivi en cours'])->assertCreated();
    }

    public function test_un_commercial_tiers_client_et_livreur_sont_exclus(): void
    {
        $commercial = $this->creerCommercial();
        $commercialTiers = $this->creerCommercialTiers();
        $produit = $this->creerProduitPhysique();
        $commande = $this->creerCommandePour($commercial, $produit);

        $client = User::findOrFail($commande->client_id);
        $livreur = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $livreur->assignRole(ROLE_LIVREUR);

        $this->actingAs($commercialTiers)->postJson("/api/v1/commandes/{$commande->id}/messages", ['contenu' => 'x'])->assertForbidden();
        $this->actingAs($client)->postJson("/api/v1/commandes/{$commande->id}/messages", ['contenu' => 'x'])->assertForbidden();
        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$commande->id}/messages", ['contenu' => 'x'])->assertForbidden();
    }
}
