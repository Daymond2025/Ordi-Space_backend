<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\User;
use App\Services\PushNotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Écran "Recherche d'un livreur" (app Fournisseur) — marquerPreparee()
 * notifie désormais les livreurs disponibles, plus "Annuler la recherche"
 * (annulerRecherche()) et "Augmenter" les frais (augmenterFraisLivraison()).
 */
class RechercheLivreurTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommandeValidee(): array
    {
        $commercial = $this->creerCommercial();
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique();
        $fournisseur = User::findOrFail($produit->fournisseur_id);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commande = Commande::findOrFail($reponse->json('data.id'));

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/valider")->assertOk();

        return [$commande, $fournisseur];
    }

    public function test_marquer_preparee_notifie_le_service_push(): void
    {
        [$commande, $fournisseur] = $this->creerCommandeValidee();

        $this->mock(PushNotificationService::class, function ($mock) {
            $mock->shouldReceive('envoyerAuxLivreursDisponibles')->once()
                ->with('Nouvelle livraison disponible', \Mockery::type('string'), \Mockery::type('array'));
        });

        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/preparee")->assertOk();

        $this->assertSame(STATUT_COMMANDE_EN_PREPARATION, $commande->fresh()->statut_commande);
        $this->assertSame(STATUT_LIVRAISON_EN_ATTENTE_LIVREUR, $commande->fresh()->livraison->statut_livraison);
    }

    public function test_annuler_recherche_remet_les_statuts_d_origine(): void
    {
        [$commande, $fournisseur] = $this->creerCommandeValidee();
        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/preparee")->assertOk();

        $reponse = $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/annuler-recherche");

        $reponse->assertOk();
        $this->assertSame(STATUT_COMMANDE_VALIDEE, $commande->fresh()->statut_commande);
        $this->assertSame(STATUT_LIVRAISON_EN_PREPARATION, $commande->fresh()->livraison->statut_livraison);
    }

    public function test_annuler_recherche_refuse_si_la_commande_n_est_pas_en_recherche(): void
    {
        [$commande, $fournisseur] = $this->creerCommandeValidee();

        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/annuler-recherche")
            ->assertStatus(422);
    }

    public function test_augmenter_frais_livraison_incremente_d_un_pas_fixe_et_notifie(): void
    {
        [$commande, $fournisseur] = $this->creerCommandeValidee();
        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/preparee")->assertOk();
        $fraisAvant = (float) $commande->fresh()->frais_livraison;

        $this->mock(PushNotificationService::class, function ($mock) {
            $mock->shouldReceive('envoyerAuxLivreursDisponibles')->once();
        });

        $reponse = $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/frais-livraison/augmenter");

        $reponse->assertOk();
        $this->assertSame($fraisAvant + FRAIS_LIVRAISON_INCREMENT, (float) $commande->fresh()->frais_livraison);
    }

    public function test_augmenter_frais_livraison_refuse_hors_recherche(): void
    {
        [$commande, $fournisseur] = $this->creerCommandeValidee();

        // Commande "validée" mais jamais envoyée en recherche (preparee()
        // jamais appelé) — pas encore dans le vivier.
        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/frais-livraison/augmenter")
            ->assertStatus(422);
    }

    public function test_un_autre_fournisseur_ne_peut_ni_annuler_ni_augmenter(): void
    {
        [$commande, $fournisseur] = $this->creerCommandeValidee();
        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/preparee")->assertOk();

        $autreFournisseur = $this->creerFournisseur();

        $this->actingAs($autreFournisseur)->postJson("/api/v1/commandes/{$commande->id}/annuler-recherche")->assertForbidden();
        $this->actingAs($autreFournisseur)->postJson("/api/v1/commandes/{$commande->id}/frais-livraison/augmenter")->assertForbidden();
    }

    public function test_seuls_les_livreurs_disponibles_sont_cibles(): void
    {
        [$commande, $fournisseur] = $this->creerCommandeValidee();

        $livreurDisponible = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $livreurDisponible->assignRole(ROLE_LIVREUR);
        \App\Models\Livreur::create(['user_id' => $livreurDisponible->id, 'disponible' => true, 'type_vehicule' => 'moto']);

        $livreurIndisponible = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $livreurIndisponible->assignRole(ROLE_LIVREUR);
        \App\Models\Livreur::create(['user_id' => $livreurIndisponible->id, 'disponible' => false, 'type_vehicule' => 'moto']);

        $appelsPourUtilisateur = [];
        $this->mock(PushNotificationService::class, function ($mock) use (&$appelsPourUtilisateur) {
            $mock->shouldReceive('envoyer')
                ->andReturnUsing(function ($user) use (&$appelsPourUtilisateur) {
                    $appelsPourUtilisateur[] = $user->id;
                });
            // envoyerAuxLivreursDisponibles() n'est pas mocké ici : on laisse
            // la vraie implémentation tourner (elle boucle les livreurs
            // "disponible" puis appelle envoyer(), mocké ci-dessus) — c'est
            // exactement le comportement qu'on veut vérifier.
            $mock->makePartial();
        });

        $this->actingAs($fournisseur)->postJson("/api/v1/commandes/{$commande->id}/preparee")->assertOk();

        $this->assertContains($livreurDisponible->id, $appelsPourUtilisateur);
        $this->assertNotContains($livreurIndisponible->id, $appelsPourUtilisateur);
    }
}
