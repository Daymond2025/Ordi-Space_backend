<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\Reclamation;
use App\Models\TechnicienMaintenance;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class ReclamationTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(IMAGE_PRODUIT_DISQUE);
    }

    private function creerLivreur(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id]);

        return $user;
    }

    private function creerTechnicien(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_TECHNICIEN_MAINTENANCE]);
        $user->assignRole(ROLE_TECHNICIEN_MAINTENANCE);
        TechnicienMaintenance::create(['user_id' => $user->id]);

        return $user;
    }

    public function test_un_client_peut_deposer_une_reclamation(): void
    {
        $client = $this->creerClient();

        $reponse = $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Livraison en retard',
            'description' => 'Ma commande devait arriver hier.',
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('reclamations', ['client_id' => $client->id, 'statut' => STATUT_RECLAMATION_NOUVELLE]);
    }

    public function test_un_client_ne_voit_que_ses_propres_reclamations(): void
    {
        $clientA = $this->creerClient();
        $clientB = $this->creerClient();

        $this->actingAs($clientA)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet A', 'description' => 'Description A',
        ])->assertCreated();
        $this->actingAs($clientB)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet B', 'description' => 'Description B',
        ])->assertCreated();

        $reponse = $this->actingAs($clientA)->getJson('/api/v1/reclamations');
        $sujets = collect($reponse->json('data.data') ?? $reponse->json('data'))->pluck('sujet');

        $this->assertContains('Sujet A', $sujets);
        $this->assertNotContains('Sujet B', $sujets);
    }

    public function test_un_admin_peut_repondre_a_une_reclamation_et_le_client_voit_la_reponse(): void
    {
        $client = $this->creerClient();
        $admin = $this->creerAdmin();

        $creation = $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Produit défectueux', 'description' => 'Écran cassé à la livraison.',
        ]);
        $id = $creation->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/reclamations/{$id}/repondre", [
            'statut' => STATUT_RECLAMATION_RESOLUE,
            'reponse_admin' => 'Un nouvel écran vous a été envoyé.',
        ])->assertOk();

        $vueClient = $this->actingAs($client)->getJson("/api/v1/reclamations/{$id}");
        $vueClient->assertOk();
        $this->assertSame('resolue', $vueClient->json('data.statut'));
        $this->assertSame('Un nouvel écran vous a été envoyé.', $vueClient->json('data.reponse_admin'));
    }

    public function test_un_client_ne_peut_pas_repondre_a_une_reclamation(): void
    {
        $client = $this->creerClient();

        $creation = $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet', 'description' => 'Description',
        ]);
        $id = $creation->json('data.id');

        $this->actingAs($client)->patchJson("/api/v1/reclamations/{$id}/repondre", [
            'statut' => STATUT_RECLAMATION_RESOLUE,
            'reponse_admin' => 'Je me réponds moi-même',
        ])->assertForbidden();
    }

    private function assertDeposeReclamationSansClientId(User $user): void
    {
        $reponse = $this->actingAs($user)->postJson('/api/v1/reclamations', [
            'sujet' => 'Problème rencontré',
            'description' => 'Détails du problème.',
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('reclamations', [
            'user_id' => $user->id,
            'client_id' => null,
            'statut' => STATUT_RECLAMATION_NOUVELLE,
        ]);
    }

    public function test_un_fournisseur_peut_deposer_une_reclamation_sans_client_id(): void
    {
        $this->assertDeposeReclamationSansClientId($this->creerFournisseur());
    }

    public function test_un_commercial_peut_deposer_une_reclamation_sans_client_id(): void
    {
        $this->assertDeposeReclamationSansClientId($this->creerCommercial());
    }

    public function test_un_livreur_peut_deposer_une_reclamation_sans_client_id(): void
    {
        $this->assertDeposeReclamationSansClientId($this->creerLivreur());
    }

    public function test_un_technicien_peut_deposer_une_reclamation_sans_client_id(): void
    {
        $this->assertDeposeReclamationSansClientId($this->creerTechnicien());
    }

    public function test_une_entite_ne_voit_que_ses_propres_reclamations(): void
    {
        $livreurA = $this->creerLivreur();
        $livreurB = $this->creerLivreur();

        $this->actingAs($livreurA)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet livreur A', 'description' => 'Description A',
        ])->assertCreated();
        $this->actingAs($livreurB)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet livreur B', 'description' => 'Description B',
        ])->assertCreated();

        $reponse = $this->actingAs($livreurA)->getJson('/api/v1/reclamations');
        $sujets = collect($reponse->json('data.data') ?? $reponse->json('data'))->pluck('sujet');

        $this->assertContains('Sujet livreur A', $sujets);
        $this->assertNotContains('Sujet livreur B', $sujets);
    }

    public function test_le_coordinateur_voit_toutes_les_entites_et_peut_repondre(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $fournisseur = $this->creerFournisseur();
        $client = $this->creerClient();

        $this->actingAs($fournisseur)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet fournisseur', 'description' => 'Description fournisseur',
        ])->assertCreated();
        $creationClient = $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet client', 'description' => 'Description client',
        ]);

        $liste = $this->actingAs($coordinateur)->getJson('/api/v1/reclamations');
        $sujets = collect($liste->json('data.data') ?? $liste->json('data'))->pluck('sujet');
        $this->assertContains('Sujet fournisseur', $sujets);
        $this->assertContains('Sujet client', $sujets);

        $this->actingAs($coordinateur)->patchJson("/api/v1/reclamations/{$creationClient->json('data.id')}/repondre", [
            'statut' => STATUT_RECLAMATION_EN_COURS,
            'reponse_admin' => 'Prise en charge par le coordinateur.',
        ])->assertOk();
    }

    public function test_le_filtre_type_auteur_restreint_a_une_entite(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $fournisseur = $this->creerFournisseur();
        $client = $this->creerClient();

        $this->actingAs($fournisseur)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet fournisseur', 'description' => 'Description fournisseur',
        ])->assertCreated();
        $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet client', 'description' => 'Description client',
        ])->assertCreated();

        $liste = $this->actingAs($coordinateur)->getJson('/api/v1/reclamations?type_auteur='.ROLE_FOURNISSEUR);
        $sujets = collect($liste->json('data.data') ?? $liste->json('data'))->pluck('sujet');

        $this->assertContains('Sujet fournisseur', $sujets);
        $this->assertNotContains('Sujet client', $sujets);
    }

    public function test_le_titre_est_deduit_du_sujet_quand_il_est_absent(): void
    {
        $client = $this->creerClient();

        $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Livraison en retard', 'description' => 'Ma commande devait arriver hier.',
        ])->assertCreated();

        $this->assertDatabaseHas('reclamations', ['sujet' => 'Livraison en retard', 'titre' => 'Livraison en retard']);
    }

    public function test_un_titre_explicite_est_conserve(): void
    {
        $client = $this->creerClient();

        $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Livraison', 'titre' => 'Le colis est arrivé cassé', 'description' => 'Détails.',
        ])->assertCreated();

        $this->assertDatabaseHas('reclamations', ['sujet' => 'Livraison', 'titre' => 'Le colis est arrivé cassé']);
    }

    public function test_lauteur_peut_ajouter_puis_supprimer_une_preuve(): void
    {
        $fournisseur = $this->creerFournisseur();

        $creation = $this->actingAs($fournisseur)->postJson('/api/v1/reclamations', [
            'sujet' => 'Commission incorrecte', 'description' => 'Détails.',
        ]);
        $id = $creation->json('data.id');

        $ajout = $this->actingAs($fournisseur)->postJson("/api/v1/reclamations/{$id}/preuves", [
            'images' => [UploadedFile::fake()->create('preuve.jpg', 100, 'image/jpeg')],
        ]);
        $ajout->assertCreated();
        $preuveId = $ajout->json('data.0.id');

        $detail = $this->actingAs($fournisseur)->getJson("/api/v1/reclamations/{$id}");
        $this->assertCount(1, $detail->json('data.preuves'));

        $this->actingAs($fournisseur)->deleteJson("/api/v1/reclamations/{$id}/preuves/{$preuveId}")->assertOk();
        $this->assertDatabaseMissing('preuves_reclamation', ['id' => $preuveId]);
    }

    public function test_un_autre_fileur_ne_peut_pas_ajouter_une_preuve_sur_la_reclamation_dautrui(): void
    {
        $fournisseurA = $this->creerFournisseur();
        $fournisseurB = $this->creerFournisseur();

        $creation = $this->actingAs($fournisseurA)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet', 'description' => 'Description',
        ]);
        $id = $creation->json('data.id');

        $this->actingAs($fournisseurB)->postJson("/api/v1/reclamations/{$id}/preuves", [
            'images' => [UploadedFile::fake()->create('preuve.jpg', 100, 'image/jpeg')],
        ])->assertForbidden();
    }

    public function test_le_coordinateur_peut_ajouter_une_preuve_sur_nimporte_quelle_reclamation(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $client = $this->creerClient();

        $creation = $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Sujet', 'description' => 'Description',
        ]);
        $id = $creation->json('data.id');

        $this->actingAs($coordinateur)->postJson("/api/v1/reclamations/{$id}/preuves", [
            'images' => [UploadedFile::fake()->create('preuve.jpg', 100, 'image/jpeg')],
        ])->assertCreated();
    }

    public function test_le_detail_inclut_le_produit_et_le_fournisseur_lies_a_la_commande(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $client = $this->creerClient();
        $ligne = $this->creerAchatLivre($client);

        $reclamation = Reclamation::create([
            'user_id' => $client->id,
            'client_id' => $client->id,
            'commande_id' => $ligne->commande_id,
            'sujet' => 'Produit défectueux',
            'titre' => 'Produit défectueux',
            'description' => 'Détails.',
            'statut' => STATUT_RECLAMATION_NOUVELLE,
            'date_reclamation' => now(),
        ]);

        $detail = $this->actingAs($coordinateur)->getJson("/api/v1/reclamations/{$reclamation->id}");

        $detail->assertOk();
        $this->assertSame($ligne->produit->nom_produit, $detail->json('data.commande.produit.nom_produit'));
        $this->assertSame('Fournisseur Test', $detail->json('data.commande.fournisseur.nom_entreprise'));
    }
}
