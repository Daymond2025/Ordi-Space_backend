<?php

namespace Tests\Feature;

use App\Models\Message;
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

    public function test_seuls_les_produits_avec_activite_apparaissent_tries_par_recence(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produitSansActivite = $this->creerProduitPhysique();
        $produitAncien = $this->creerProduitPhysique();
        $produitRecent = $this->creerProduitPhysique();

        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produitAncien->id}/messages", ['contenu' => 'Ancien']);
        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produitRecent->id}/messages", ['contenu' => 'Récent']);

        // Timestamps forcés distincts : le test ne doit pas dépendre de la
        // rapidité d'exécution pour garantir un ordre déterministe.
        Message::where('produit_id', $produitAncien->id)->update(['date_envoi' => now()->subHour()]);
        Message::where('produit_id', $produitRecent->id)->update(['date_envoi' => now()]);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/produits/activite-recente');

        $reponse->assertOk();
        $ids = collect($reponse->json('data'))->pluck('produit_id');

        $this->assertFalse($ids->contains($produitSansActivite->id));
        $this->assertSame($produitRecent->id, $ids->first());
    }

    public function test_les_nouvelles_activites_descendent_a_zero_apres_consultation(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();

        $this->actingAs($commercial)->postJson("/api/v1/produits/{$produit->id}/messages", ['contenu' => 'Client intéressé']);

        $avant = $this->actingAs($coordinateur)->getJson('/api/v1/produits/activite-recente');
        $avant->assertOk();
        $ligneAvant = collect($avant->json('data'))->firstWhere('produit_id', $produit->id);
        $this->assertSame(1, $ligneAvant['nouvelles_activites']);

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

        $this->actingAs($this->creerCoordinateur())->postJson("/api/v1/produits/{$produitA->id}/messages", ['contenu' => 'x']);
        $this->actingAs($this->creerCoordinateur())->postJson("/api/v1/produits/{$produitB->id}/messages", ['contenu' => 'x']);

        $reponse = $this->actingAs($proprietaireA)->getJson('/api/v1/produits/activite-recente');

        $reponse->assertOk();
        $ids = collect($reponse->json('data'))->pluck('produit_id');
        $this->assertTrue($ids->contains($produitA->id));
        $this->assertFalse($ids->contains($produitB->id));
    }
}
