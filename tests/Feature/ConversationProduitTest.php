<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Produit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class ConversationProduitTest extends TestCase
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

    public function test_le_flux_fusionne_contient_messages_et_cartes_commande(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();

        $this->creerCommandePour($commercial, $produit);
        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/messages", ['contenu' => 'Bonjour']);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/conversation");

        $reponse->assertOk();
        $types = collect($reponse->json('data.items'))->pluck('type');
        $this->assertTrue($types->contains('message'));
        $this->assertTrue($types->contains('commande'));
    }

    public function test_le_filtre_statut_ne_touche_que_les_cartes_commande(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 10]);

        $commandeEnAttente = $this->creerCommandePour($commercial, $produit);
        $commandeLivree = $this->creerCommandePour($commercial, $produit);
        $commandeLivree->update(['statut_commande' => STATUT_COMMANDE_LIVREE]);
        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/messages", ['contenu' => 'Info générale']);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/conversation?statut=livree");

        $reponse->assertOk();
        $items = collect($reponse->json('data.items'));
        $cartes = $items->where('type', 'commande')->pluck('donnee.commande_id');

        $this->assertTrue($cartes->contains($commandeLivree->id));
        $this->assertFalse($cartes->contains($commandeEnAttente->id));
        $this->assertTrue($items->where('type', 'message')->isNotEmpty());
    }

    public function test_un_commercial_ne_voit_que_ses_propres_cartes_mais_tous_les_messages(): void
    {
        $commercial = $this->creerCommercial();
        $commercialTiers = $this->creerCommercialTiers();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 10]);

        $commandeCommercial = $this->creerCommandePour($commercial, $produit);
        $commandeTiers = $this->creerCommandePour($commercialTiers, $produit);
        $this->actingAs($commercialTiers)->postJson("/api/v1/produits/{$produit->id}/messages", ['contenu' => 'Message du tiers']);

        $reponse = $this->actingAs($commercial)->getJson("/api/v1/produits/{$produit->id}/conversation?statut=tous");

        $reponse->assertOk();
        $items = collect($reponse->json('data.items'));
        $cartes = $items->where('type', 'commande')->pluck('donnee.commande_id');

        $this->assertTrue($cartes->contains($commandeCommercial->id));
        $this->assertFalse($cartes->contains($commandeTiers->id));
        $this->assertTrue($items->where('type', 'message')->isNotEmpty());
    }

    public function test_les_stats_d_en_tete_forment_une_partition_complete(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 10]);

        $livree = $this->creerCommandePour($commercial, $produit);
        $livree->update(['statut_commande' => STATUT_COMMANDE_LIVREE]);

        $annulee = $this->creerCommandePour($commercial, $produit);
        $annulee->update(['statut_commande' => STATUT_COMMANDE_ANNULEE]);

        $enAttente = $this->creerCommandePour($commercial, $produit);
        $probleme = $this->creerCommandePour($commercial, $produit);
        $probleme->update(['statut_commande' => STATUT_COMMANDE_NUMERO_INCORRECT]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/conversation");

        $reponse->assertOk();
        $enTete = $reponse->json('data.en_tete');

        $this->assertEquals(4, $enTete['recues']);
        $this->assertEquals(1, $enTete['livrees']);
        $this->assertEquals(1, $enTete['annulees']);
        $this->assertEquals(2, $enTete['en_cours']); // en_attente + numero_incorrect
        $this->assertEquals($enTete['recues'], $enTete['livrees'] + $enTete['en_cours'] + $enTete['annulees']);
        $this->assertNotNull($enAttente);
    }

    public function test_la_carte_commande_expose_description_zone_localite_et_dernier_suivi(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique(['description' => 'Ordinateur portable gaming']);

        $commande = $this->creerCommandePour($commercial, $produit);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/conversation");

        $reponse->assertOk();
        $carte = collect($reponse->json('data.items'))->first(fn ($item) => $item['type'] === 'commande' && $item['donnee']['commande_id'] === $commande->id)['donnee'];

        $this->assertSame('Ordinateur portable gaming', $carte['description']);
        $this->assertSame("Abidjan, Cocody", $carte['zone_localite']);
        $this->assertNotNull($carte['dernier_suivi']);
        $this->assertStringContainsString('passé une commande', $carte['dernier_suivi']['texte']);
    }

    public function test_le_badge_non_lu_par_commande_descend_a_zero_apres_consultation(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);

        $commande = $this->creerCommandePour($commercial, $produit);

        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/messages", [
            'contenu' => 'Numéro incorrect, à vérifier.',
        ])->assertCreated();

        $avant = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/conversation");
        $avant->assertOk();
        $carteAvant = collect($avant->json('data.items'))->first(fn ($item) => $item['type'] === 'commande' && $item['donnee']['commande_id'] === $commande->id)['donnee'];
        // 2 : le message système "commande_creee" (auteur = le commercial qui a
        // passé la commande) + le message du fournisseur — aucun des deux n'est
        // du coordinateur qui consulte ici.
        $this->assertSame(2, $carteAvant['nouvelles_activites']);

        $this->actingAs($coordinateur)->getJson("/api/v1/commandes/{$commande->id}/messages")->assertOk();

        $apres = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/conversation");
        $carteApres = collect($apres->json('data.items'))->first(fn ($item) => $item['type'] === 'commande' && $item['donnee']['commande_id'] === $commande->id)['donnee'];
        $this->assertSame(0, $carteApres['nouvelles_activites']);
    }
}
