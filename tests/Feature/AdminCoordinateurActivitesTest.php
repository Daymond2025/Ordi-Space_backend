<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\JournalAudit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * GET /admin/coordinateurs/{coordinateur}/activites — Espace Coordinateur
 * côté Admin (superviseur global) : même journal que MoiController::activites(),
 * mais consultable par l'Admin pour n'importe quel coordinateur.
 */
class AdminCoordinateurActivitesTest extends TestCase
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

    public function test_ladmin_voit_les_activites_dun_coordinateur_precis(): void
    {
        $admin = $this->creerAdmin();
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

        $reponse = $this->actingAs($admin)->getJson("/api/v1/admin/coordinateurs/{$coordinateur->id}/activites?periode=tout");

        $reponse->assertOk();
        $activites = collect($reponse->json('data.data'));
        $this->assertTrue($activites->isNotEmpty());
        $this->assertTrue($activites->every(fn ($a) => $a['acteur_id'] === $coordinateur->id));
    }

    public function test_un_non_administrateur_ne_peut_pas_consulter_lactivite_dun_coordinateur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $fournisseur = $this->creerFournisseur();

        $this->actingAs($fournisseur)
            ->getJson("/api/v1/admin/coordinateurs/{$coordinateur->id}/activites")
            ->assertForbidden();
    }
}
