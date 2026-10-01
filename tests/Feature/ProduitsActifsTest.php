<?php

namespace Tests\Feature;

use App\Models\Produit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class ProduitsActifsTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** Passe une vraie commande sur `$produit` (seul moyen désormais de faire apparaître un produit dans "activité récente" — voir produitsActifs()). */
    private function passerCommande(Produit $produit): void
    {
        $commercial = $this->creerCommercial();
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        $reponse->assertCreated();
    }

    public function test_seuls_les_produits_avec_une_vraie_commande_apparaissent_tries_par_recence(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produitSansCommande = $this->creerProduitPhysique();
        $produitAncien = $this->creerProduitPhysique();
        $produitRecent = $this->creerProduitPhysique();

        // Un simple message, SANS commande, ne suffit plus à faire apparaître le produit (retour de test réel).
        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produitSansCommande->id}/messages", ['contenu' => 'Juste une question']);

        $this->passerCommande($produitAncien);
        $this->travel(1)->second();
        $this->passerCommande($produitRecent);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/produits/activite-recente');

        $reponse->assertOk();
        $ids = collect($reponse->json('data'))->pluck('produit_id');

        $this->assertFalse($ids->contains($produitSansCommande->id));
        $this->assertTrue($ids->contains($produitAncien->id));
        $this->assertSame($produitRecent->id, $ids->first());
    }

    public function test_les_nouvelles_activites_descendent_a_zero_apres_consultation(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();

        // passerCommande() publie déjà un message système ("commande créée",
        // auteur = commercial) — donc 1 non-lu pour le coordinateur avant
        // même le message explicite ci-dessous (même mécanique que
        // FournisseurCommandesEtProduitsTest::test_le_badge_nouvelles_activites...).
        $this->passerCommande($produit);
        $this->actingAs($commercial)->postJson("/api/v1/produits/{$produit->id}/messages", ['contenu' => 'Client intéressé']);

        $avant = $this->actingAs($coordinateur)->getJson('/api/v1/produits/activite-recente');
        $avant->assertOk();
        $ligneAvant = collect($avant->json('data'))->firstWhere('produit_id', $produit->id);
        $this->assertSame(2, $ligneAvant['nouvelles_activites']);

        $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/messages")->assertOk();

        $apres = $this->actingAs($coordinateur)->getJson('/api/v1/produits/activite-recente');
        $ligneApres = collect($apres->json('data'))->firstWhere('produit_id', $produit->id);
        $this->assertSame(0, $ligneApres['nouvelles_activites']);
    }

    public function test_le_fournisseur_ne_voit_que_ses_propres_produits(): void
    {
        $produitA = $this->creerProduitPhysique();
        $produitB = $this->creerProduitPhysique();
        $proprietaireA = User::findOrFail($produitA->fournisseur_id);

        $this->passerCommande($produitA);
        $this->passerCommande($produitB);

        $reponse = $this->actingAs($proprietaireA)->getJson('/api/v1/produits/activite-recente');

        $reponse->assertOk();
        $ids = collect($reponse->json('data'))->pluck('produit_id');
        $this->assertTrue($ids->contains($produitA->id));
        $this->assertFalse($ids->contains($produitB->id));
    }

    public function test_une_carte_epinglee_reste_en_tete_malgre_une_activite_plus_recente_ailleurs(): void
    {
        $produitEpingle = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produitEpingle->fournisseur_id);
        $produitRecent = $this->creerProduitPhysique(['fournisseur_id' => $fournisseur->id]);

        $this->passerCommande($produitEpingle);
        $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produitEpingle->id}/epingler-accueil")->assertOk();

        $this->travel(1)->second();
        $this->passerCommande($produitRecent);

        $reponse = $this->actingAs($fournisseur)->getJson('/api/v1/produits/activite-recente');
        $reponse->assertOk();
        $donnees = collect($reponse->json('data'));

        $this->assertSame($produitEpingle->id, $donnees->first()['produit_id']);
        $this->assertTrue($donnees->firstWhere('produit_id', $produitEpingle->id)['epingle']);

        // Désépingler fait retomber la carte dans le tri normal par activité.
        $this->actingAs($fournisseur)->deleteJson("/api/v1/produits/{$produitEpingle->id}/epingler-accueil")->assertOk();
        $apres = $this->actingAs($fournisseur)->getJson('/api/v1/produits/activite-recente');
        $this->assertSame($produitRecent->id, collect($apres->json('data'))->first()['produit_id']);
    }

    public function test_une_carte_retiree_disparait_puis_reapparait_a_une_nouvelle_activite(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);

        $this->passerCommande($produit);
        $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produit->id}/retirer-accueil")->assertOk();

        $apresRetrait = $this->actingAs($fournisseur)->getJson('/api/v1/produits/activite-recente');
        $this->assertFalse(collect($apresRetrait->json('data'))->pluck('produit_id')->contains($produit->id));

        // Une nouvelle commande (activité postérieure au retrait) fait réapparaître la carte.
        $this->travel(1)->second();
        $this->passerCommande($produit);

        $apresNouvelleActivite = $this->actingAs($fournisseur)->getJson('/api/v1/produits/activite-recente');
        $this->assertTrue(collect($apresNouvelleActivite->json('data'))->pluck('produit_id')->contains($produit->id));
    }
}
