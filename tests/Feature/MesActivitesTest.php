<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\JournalAudit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * GET /moi/activites — onglet "Mes activités" (écran Compte) : journal des
 * actions effectuées par l'utilisateur connecté (acteur_id), filtrable par
 * période (resoudre_periode(), partagée avec EspaceController).
 */
class MesActivitesTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommande(): Commande
    {
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        return Commande::findOrFail($reponse->json('data.id'));
    }

    public function test_ne_montre_que_les_activites_du_coordinateur_connecte(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $autreCoordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommande();

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/statut", [
            'statut_commande' => STATUT_COMMANDE_VALIDEE,
        ])->assertOk();

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            'Action d\'un autre coordinateur.',
            commandeId: $commande->id,
            acteurId: $autreCoordinateur->id,
        );

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/moi/activites?periode=tout');

        $reponse->assertOk();
        $activites = collect($reponse->json('data.data'));
        $this->assertTrue($activites->isNotEmpty());
        $this->assertTrue($activites->every(fn ($a) => $a['acteur_id'] === $coordinateur->id));
    }

    public function test_filtre_par_periode(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommande();

        JournalAudit::enregistrer(
            $commande->client_id,
            ACTION_COMMANDE_STATUT_MODIFIE,
            'commande',
            'Activité ancienne.',
            commandeId: $commande->id,
            acteurId: $coordinateur->id,
        );
        JournalAudit::where('details', 'Activité ancienne.')->update(['date_heure' => now()->subMonths(2)]);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/moi/activites?periode=semaine');

        $details = collect($reponse->json('data.data'))->pluck('details');
        $this->assertFalse($details->contains('Activité ancienne.'));
    }
}
