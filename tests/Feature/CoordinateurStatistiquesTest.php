<?php

namespace Tests\Feature;

use App\Models\CanalVente;
use App\Models\Commande;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class CoordinateurStatistiquesTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommande(string $statut, \DateTimeInterface $date): Commande
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();

        return Commande::create([
            'client_id' => $client->id,
            'commercial_id' => $this->creerAgentIa()->id,
            'canal_vente_id' => CanalVente::firstOrFail()->id,
            'statut_commande' => $statut,
            'montant_total' => $produit->prix,
            'date_commande' => $date,
        ]);
    }

    public function test_les_compteurs_par_statut_et_periode_sont_corrects(): void
    {
        $coordinateur = $this->creerCoordinateur();

        $this->creerCommande(STATUT_COMMANDE_EN_ATTENTE, now());
        $this->creerCommande(STATUT_COMMANDE_LIVREE, now());
        $this->creerCommande(STATUT_COMMANDE_ANNULEE, now()->subWeeks(2));

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/espace/statistiques?periode=aujourd_hui');

        $reponse->assertOk();
        $reponse->assertJsonPath('data.commandes_recues', 2);
        $reponse->assertJsonPath('data.commandes_en_attente', 1);
        $reponse->assertJsonPath('data.commandes_livrees', 1);
    }

    public function test_la_plage_de_dates_explicite_fonctionne(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $this->creerCommande(STATUT_COMMANDE_LIVREE, now()->subDays(10));

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/espace/statistiques?date_debut='.
            now()->subDays(15)->toDateString().'&date_fin='.now()->subDays(5)->toDateString());

        $reponse->assertOk();
        $reponse->assertJsonPath('data.commandes_recues', 1);
    }

    public function test_un_fournisseur_ne_peut_pas_acceder_aux_statistiques_coordinateur(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = \App\Models\User::findOrFail($produit->fournisseur_id);

        $this->actingAs($fournisseur)->getJson('/api/v1/coordinateur/espace/statistiques')->assertForbidden();
    }
}
