<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\DemandeSav;
use App\Models\Localite;
use App\Models\Reclamation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Corrections Admin (retours des administrateurs) :
 *  1. "Nouveau client" : plus jamais "données invalides" avec un client
 *     quand même enregistré — tout ou rien (une seule transaction) ;
 *  2. Suppression définitive d'un client : compte, accès et données liées.
 */
class AdminClientCorrectionsTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function payload(int $produitId, int $livreurId, int $quantite = 1, string $telephone = '0505050505'): array
    {
        return [
            'nom' => 'Kouadio', 'prenom' => 'Ange', 'telephone' => $telephone,
            'localite_id' => Localite::where('nom', 'Cocody')->value('id'),
            'livreur_id' => $livreurId,
            'commande' => [
                'lignes' => [['produit_id' => $produitId, 'quantite' => $quantite, 'prix_unitaire' => 450000, 'duree_garantie_mois' => 6]],
                'frais_livraison' => 2000,
                'date_livraison_prevue' => now()->addDay()->format('Y-m-d H:i:s'),
            ],
        ];
    }

    public function test_nouveau_client_et_commande_enregistres_en_une_fois(): void
    {
        $this->creerAgentIa();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5]);
        $livreur = $this->creerLivreur();

        $reponse = $this->actingAs($this->creerAdmin())->postJson('/api/v1/admin/clients/nouvelle-commande', $this->payload($produit->id, $livreur->id))
            ->assertCreated()->json('data');

        $commande = Commande::with('livraison', 'lignes')->findOrFail($reponse['commande_id']);
        $this->assertSame($reponse['client_id'], $commande->client_id);
        $this->assertSame($livreur->id, $commande->livraison->livreur_id);
        $this->assertEquals(450000, $commande->lignes->first()->prix_unitaire);
        $this->assertSame('+2250505050505', User::find($reponse['client_id'])->telephone);
    }

    public function test_commande_refusee_aucun_client_ni_adresse_ne_reste_enregistre(): void
    {
        $this->creerAgentIa();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 1]);
        $livreur = $this->creerLivreur();
        $clientsAvant = User::where('type_utilisateur', ROLE_CLIENT)->count();

        // Stock insuffisant : la commande est refusée…
        $this->actingAs($this->creerAdmin())->postJson('/api/v1/admin/clients/nouvelle-commande', $this->payload($produit->id, $livreur->id, quantite: 3))
            ->assertUnprocessable()->assertJsonValidationErrors('lignes', 'error.fields');

        // …et rien n'a été enregistré : ni client, ni adresse, ni commande, stock intact.
        $this->assertSame($clientsAvant, User::where('type_utilisateur', ROLE_CLIENT)->count());
        $this->assertNull(User::where('telephone', '+2250505050505')->first());
        $this->assertSame(0, Commande::count());
        $this->assertSame(1, $produit->fresh()->quantite_stock);
    }

    public function test_suppression_definitive_d_un_client_et_de_ses_donnees(): void
    {
        $this->creerAgentIa();
        $admin = $this->creerAdmin();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5]);
        $livreur = $this->creerLivreur();

        $cible = $this->actingAs($admin)->postJson('/api/v1/admin/clients/nouvelle-commande', $this->payload($produit->id, $livreur->id, quantite: 2))->json('data');
        $autre = $this->actingAs($admin)->postJson('/api/v1/admin/clients/nouvelle-commande', $this->payload($produit->id, $livreur->id, telephone: '0707070707'))->json('data');
        $this->assertSame(2, $produit->fresh()->quantite_stock);

        $client = User::findOrFail($cible['client_id']);
        $client->createToken('appli');
        DemandeSav::create(['client_id' => $client->id, 'description_probleme' => 'Écran noir', 'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE, 'date_demande' => now()]);
        Reclamation::create([
            'client_id' => $client->id, 'user_id' => $client->id, 'commande_id' => $cible['commande_id'], 'sujet' => 'livraison',
            'titre' => 'Retard', 'description' => 'Livraison en retard', 'statut' => 'nouvelle',
        ]);

        // Ni un coordinateur, ni un compte non client.
        $this->actingAs($this->creerCoordinateur())->deleteJson("/api/v1/admin/clients/{$client->id}")->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/v1/admin/clients/{$livreur->id}")->assertUnprocessable();

        $this->actingAs($admin)->deleteJson("/api/v1/admin/clients/{$client->id}")->assertOk();

        $this->assertNull(User::find($client->id));
        $this->assertSame(0, \DB::table('clients')->where('user_id', $client->id)->count());
        $this->assertSame(0, \DB::table('adresses')->where('client_id', $client->id)->count());
        $this->assertSame(0, \DB::table('personal_access_tokens')->where('tokenable_id', $client->id)->count());
        $this->assertSame(0, DemandeSav::where('client_id', $client->id)->count());
        $this->assertSame(0, Reclamation::where('client_id', $client->id)->count());
        $this->assertNull(Commande::find($cible['commande_id']));
        // Stock réservé par sa commande en cours (2) rendu au catalogue.
        $this->assertSame(4, $produit->fresh()->quantite_stock);
        // Il disparaît de la liste, l'autre client est intact.
        $ids = collect($this->actingAs($admin)->getJson('/api/v1/admin/clients?per_page=100')->json('data.data'))->pluck('id');
        $this->assertNotContains($client->id, $ids);
        $this->assertNotNull(Commande::find($autre['commande_id']));
        // Il ne peut plus se connecter : le numéro n'est plus connu.
        $this->postJson('/api/v1/auth/telephone/otp', ['telephone' => '0505050505'])->assertOk()->assertJsonPath('data.compte_existant', false);
    }
}
