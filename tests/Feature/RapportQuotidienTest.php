<?php

namespace Tests\Feature;

use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\LigneCommande;
use App\Models\Message;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class RapportQuotidienTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommandeHier(int $prix): Commande
    {
        $commercial = $this->creerCommercial();
        $canal = CanalVente::firstOrCreate(['nom_canal' => 'Boutique en ligne']);

        return Commande::create([
            'client_id' => $this->creerClient()->id,
            'commercial_id' => $commercial->id,
            'canal_vente_id' => $canal->id,
            'statut_commande' => STATUT_COMMANDE_LIVREE,
            'montant_total' => $prix,
            'date_commande' => now()->subDay(),
        ]);
    }

    public function test_le_rapport_est_genere_une_seule_fois_par_jour_avec_le_bon_contenu(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['prix' => 50000]);

        $commande = $this->creerCommandeHier(50000);
        LigneCommande::create([
            'commande_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire' => 50000,
        ]);

        $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/conversation")->assertOk();

        $this->assertSame(1, Message::where('produit_id', $produit->id)->where('type', TYPE_MESSAGE_RAPPORT)->count());

        $rapport = Message::where('produit_id', $produit->id)->where('type', TYPE_MESSAGE_RAPPORT)->firstOrFail();
        $this->assertStringContainsString('1 commande', $rapport->contenu);
        $this->assertStringContainsString('1 validée', $rapport->contenu);
        $this->assertStringContainsString('0 annulée', $rapport->contenu);
        // assertEquals (pas assertSame) : la colonne MySQL `donnees` est de
        // type JSON, qui réordonne les clés d'objet en interne (par longueur
        // puis alphabétiquement) indépendamment de l'ordre d'insertion — le
        // contenu décodé est correct, seul l'ordre des clés diffère.
        $this->assertEquals([
            'date' => now()->subDay()->toDateString(),
            'envoyees' => 1,
            'validees' => 1,
            'reportees' => 0,
            'non_livre' => 0,
            'annulees' => 0,
        ], $rapport->donnees);

        // Second appel le même jour : pas de doublon.
        $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/conversation")->assertOk();
        $this->assertSame(1, Message::where('produit_id', $produit->id)->where('type', TYPE_MESSAGE_RAPPORT)->count());
    }

    public function test_aucun_rapport_si_aucune_activite_hier(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();

        $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/conversation")->assertOk();

        $this->assertDatabaseMissing('messages', ['produit_id' => $produit->id, 'type' => TYPE_MESSAGE_RAPPORT]);
    }
}
