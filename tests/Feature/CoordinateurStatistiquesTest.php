<?php

namespace Tests\Feature;

use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\Fournisseur;
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

    public function test_commandes_validees_et_en_cours_sont_correctement_comptees(): void
    {
        $coordinateur = $this->creerCoordinateur();

        $validee = $this->creerCommande(STATUT_COMMANDE_VALIDEE, now());
        $validee->update(['coordinateur_id' => $coordinateur->id]);

        $this->creerCommande(STATUT_COMMANDE_EN_ATTENTE, now());
        $this->creerCommande(STATUT_COMMANDE_LIVREE, now());
        $this->creerCommande(STATUT_COMMANDE_ANNULEE, now());

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/espace/statistiques?periode=aujourd_hui');

        $reponse->assertOk();
        $reponse->assertJsonPath('data.commandes_validees', 1);
        // "en cours" = ni livrée ni annulée => validée + en_attente = 2.
        $reponse->assertJsonPath('data.commandes_en_cours', 2);
    }

    public function test_commissions_generee_et_distribuee_sont_correctes(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['prix' => 100000]);
        $fournisseur = Fournisseur::findOrFail($produit->fournisseur_id);
        $fournisseur->update(['taux_commission' => 20]);

        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);
        $admin = $this->creerAdmin();

        $creation = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commandeId = $creation->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commandeId}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        // 100 000 à 20% de commission => 20 000 générés ; on n'en distribue que 15 000.
        $this->actingAs($admin)->postJson("/api/v1/fournisseurs/{$fournisseur->user_id}/portefeuille/paiement", [
            'montant' => 15000,
        ])->assertCreated();

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/espace/statistiques?periode=aujourd_hui');

        $reponse->assertOk();
        $reponse->assertJsonPath('data.commission_generee', 20000);
        $reponse->assertJsonPath('data.commission_distribuee', 15000);
    }

    public function test_periode_tout_leve_la_restriction_de_date(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $this->creerCommande(STATUT_COMMANDE_LIVREE, now()->subYears(2));

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/espace/statistiques?periode=tout');

        $reponse->assertOk();
        $reponse->assertJsonPath('data.commandes_recues', 1);
    }

    public function test_un_fournisseur_ne_peut_pas_acceder_aux_statistiques_coordinateur(): void
    {
        $produit = $this->creerProduitPhysique();
        $fournisseur = \App\Models\User::findOrFail($produit->fournisseur_id);

        $this->actingAs($fournisseur)->getJson('/api/v1/coordinateur/espace/statistiques')->assertForbidden();
    }

    /**
     * L'Administrateur (superviseur global) doit accéder au même groupe de
     * routes que le Coordinateur — il a déjà toutes les permissions, seul le
     * rôle Spatie manquait (cf. routes/api.php, groupe coordinateur).
     */
    public function test_un_administrateur_peut_consulter_les_statistiques_de_lespace_coordinateur(): void
    {
        $admin = $this->creerAdmin();
        $this->creerCommande(STATUT_COMMANDE_LIVREE, now());

        $reponse = $this->actingAs($admin)->getJson('/api/v1/coordinateur/espace/statistiques?periode=aujourd_hui');

        $reponse->assertOk();
        $reponse->assertJsonPath('data.commandes_recues', 1);
    }
}
