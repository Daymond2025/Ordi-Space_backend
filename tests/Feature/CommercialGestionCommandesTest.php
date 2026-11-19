<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Localite;
use App\Models\Message;
use App\Models\Produit;
use App\Models\Tutoriel;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Gestion des commandes côté commercial : cloisonnement des conversations
 * entre commerciaux, frais et lieu de livraison modifiables, commande
 * programmée (transmise une heure avant la livraison), formations de vente.
 */
class CommercialGestionCommandesTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function commander(User $commercial, Produit $produit, array $extra = []): Commande
    {
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $id = $this->actingAs($commercial)->postJson('/api/v1/commandes', array_merge([
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ], $extra))->assertCreated()->json('data.id');

        return Commande::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_un_commercial_ne_voit_ni_les_commandes_ni_les_messages_dun_autre_commercial(): void
    {
        $produit = $this->creerProduitPhysique();
        $moi = $this->creerCommercial();
        $autre = $this->creerCommercial();

        $maCommande = $this->commander($moi, $produit);
        $commandeAutre = $this->commander($autre, $produit);
        $this->actingAs($autre)->postJson("/api/v1/produits/{$produit->id}/messages", ['contenu' => 'Message de l’autre commercial'])->assertCreated();
        $this->actingAs($this->creerCoordinateur())->postJson("/api/v1/produits/{$produit->id}/messages", ['contenu' => 'Info de la coordination'])->assertCreated();

        $fil = $this->actingAs($moi)->getJson("/api/v1/produits/{$produit->id}/conversation")->assertOk();

        $items = collect($fil->json('data.items'));
        $commandes = $items->where('type', 'commande')->pluck('donnee.commande_id');
        $messages = $items->where('type', 'message')->pluck('donnee');

        $this->assertEquals([$maCommande->id], $commandes->values()->all());
        $this->assertTrue($messages->every(fn ($m) => ($m['commande_id'] ?? null) === null || $m['commande_id'] === $maCommande->id));
        $this->assertFalse($messages->contains(fn ($m) => $m['contenu'] === 'Message de l’autre commercial'));
        $this->assertTrue($messages->contains(fn ($m) => $m['contenu'] === 'Info de la coordination'));
        $this->assertFalse($messages->contains(fn ($m) => $m['type'] === TYPE_MESSAGE_RAPPORT));
        $this->assertSame(1, $fil->json('data.en_tete.recues'));

        $this->actingAs($moi)->getJson("/api/v1/commandes/{$commandeAutre->id}")->assertForbidden();
        $this->actingAs($moi)->getJson("/api/v1/commandes/{$commandeAutre->id}/messages")->assertForbidden();
    }

    public function test_le_commercial_peut_fixer_les_frais_et_preciser_le_lieu(): void
    {
        $produit = $this->creerProduitPhysique(['prix_vente' => 300000]);
        $commercial = $this->creerCommercial();
        $client = $this->creerClient();
        $localite = Localite::where('nom', 'Yopougon')->first() ?? Localite::where('nom', '!=', 'Cocody')->firstOrFail();

        $adresse = $this->actingAs($commercial)->postJson("/api/v1/clients/{$client->id}/adresses", [
            'localite_id' => $localite->id, 'precision' => 'Carrefour Siporex, immeuble bleu',
        ])->assertCreated()->json('data');
        $this->assertSame('Carrefour Siporex, immeuble bleu', $adresse['precision_lieu']);

        // Localité sans barème : accepté parce que le commercial fixe lui-même les frais.
        $id = $this->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse['id'], 'frais_livraison' => 3500,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ])->assertCreated()->json('data.id');

        $commande = Commande::findOrFail($id);
        $this->assertEquals(3500, $commande->frais_livraison);
        $this->assertStringContainsString('Carrefour Siporex', $commande->versApercu()['zone_livraison']);
    }

    public function test_une_commande_programmee_nest_transmise_quune_heure_avant_la_livraison(): void
    {
        $produit = $this->creerProduitPhysique();
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $livraison = now()->addDay()->setTime(15, 0);

        $commande = $this->commander($commercial, $produit, ['date_livraison_prevue' => $livraison->toDateTimeString()]);
        $this->assertEquals($livraison->copy()->subHour()->toDateTimeString(), $commande->date_transmission->toDateTimeString());

        // Le commercial la voit, la coordination pas encore.
        $this->actingAs($commercial)->getJson("/api/v1/commandes/{$commande->id}")->assertOk();
        $this->actingAs($coordinateur)->getJson("/api/v1/commandes/{$commande->id}")->assertNotFound();
        $this->assertNotContains($commande->id, collect($this->getJson('/api/v1/commandes?per_page=100')->json('data.data'))->pluck('id')->all());
        $fil = collect($this->getJson("/api/v1/produits/{$produit->id}/conversation")->json('data.items'));
        $this->assertFalse($fil->contains(fn ($i) => ($i['donnee']['commande_id'] ?? null) === $commande->id));

        // Une heure avant la livraison : transmise.
        $this->travelTo($livraison->copy()->subMinutes(59));
        $this->actingAs($coordinateur)->getJson("/api/v1/commandes/{$commande->id}")->assertOk();
        $this->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();
    }

    public function test_une_livraison_immediate_est_transmise_tout_de_suite(): void
    {
        $commande = $this->commander($this->creerCommercial(), $this->creerProduitPhysique());

        $this->assertNull($commande->date_transmission);
        $this->actingAs($this->creerCoordinateur())->getJson("/api/v1/commandes/{$commande->id}")->assertOk();
    }

    public function test_une_date_de_livraison_passee_est_refusee(): void
    {
        $commercial = $this->creerCommercial();
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id, 'date_livraison_prevue' => now()->subHour()->toDateTimeString(),
            'lignes' => [['produit_id' => $this->creerProduitPhysique()->id, 'quantite' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('date_livraison_prevue', 'error.fields');
    }

    public function test_les_formations_de_vente_ne_sont_visibles_que_des_commerciaux(): void
    {
        $vente = Tutoriel::create(['titre' => 'Conclure une vente', 'type' => TYPE_TUTORIEL_FORMATION_VENTE, 'statut' => STATUT_TUTORIEL_PUBLIE, 'date_publication' => now()]);
        $tuto = Tutoriel::create(['titre' => 'Nettoyer son clavier', 'type' => TYPE_TUTORIEL_RAPIDE, 'statut' => STATUT_TUTORIEL_PUBLIE, 'date_publication' => now()]);

        $commercial = $this->creerCommercial();
        $this->actingAs($commercial)->getJson('/api/v1/tutoriels')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $vente->id);
        $this->actingAs($commercial)->getJson("/api/v1/tutoriels/{$tuto->id}")->assertNotFound();

        $client = $this->creerClient();
        $ids = collect($this->actingAs($client)->getJson('/api/v1/tutoriels')->json('data.data'))->pluck('id');
        $this->assertNotContains($vente->id, $ids);
        $this->actingAs($client)->getJson("/api/v1/tutoriels/{$vente->id}")->assertNotFound();

        $this->actingAs($this->creerAdmin())->postJson('/api/v1/tutoriels', [
            'titre' => 'Répondre aux objections', 'type' => TYPE_TUTORIEL_FORMATION_VENTE, 'statut' => STATUT_TUTORIEL_PUBLIE,
        ])->assertCreated();
    }

    public function test_les_reponses_rapides_sont_installees_par_le_seeder(): void
    {
        $this->assertDatabaseHas('reponses_rapides', ['titre' => 'Objection à une demande de réduction']);
        $this->assertDatabaseCount('reponses_rapides', 4);

        // Idempotent : relancer le seeder (déploiement) ne duplique rien.
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('reponses_rapides', 4);
    }
}
