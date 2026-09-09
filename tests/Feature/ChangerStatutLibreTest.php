<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Livreur;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Écran détail commande (Espace Coordinateur) : liberté totale de statut,
 * sans passer par la machine à états stricte de traiterProbleme().
 */
class ChangerStatutLibreTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommande(User $commercial): Commande
    {
        $produit = $this->creerProduitPhysique();
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        return Commande::findOrFail($reponse->json('data.id'));
    }

    public function test_le_coordinateur_force_n_importe_quel_statut_depuis_n_importe_quel_statut(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommande($this->creerCommercial());
        $commande->update(['statut_commande' => STATUT_COMMANDE_EN_LIVRAISON]);

        $reponse = $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/statut", [
            'statut_commande' => STATUT_COMMANDE_ANNULEE,
            'motif' => 'Client a annulé par téléphone',
        ]);

        $reponse->assertOk();
        $this->assertDatabaseHas('commandes', ['id' => $commande->id, 'statut_commande' => STATUT_COMMANDE_ANNULEE]);

        $entree = \App\Models\JournalAudit::where('commande_id', $commande->id)->orderByDesc('id')->first();
        $this->assertNotNull($entree);
        $this->assertSame(['statut_apres' => STATUT_COMMANDE_ANNULEE], $entree->donnees);
        $this->assertStringContainsString('Client a annulé par téléphone', $entree->details);
    }

    public function test_annuler_restocke_le_produit(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5]);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
        ]);
        $commande = Commande::findOrFail($reponse->json('data.id'));
        $this->assertSame(3, $produit->fresh()->quantite_stock);

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/statut", [
            'statut_commande' => STATUT_COMMANDE_ANNULEE,
        ])->assertOk();

        $this->assertSame(5, $produit->fresh()->quantite_stock);
    }

    /**
     * Le livreur a déjà le colis (mission acceptée, en_cours) quand la
     * commande est annulée : la livraison doit être signalée pour un retour
     * physique au dépôt — voir Commande::appliquerChangementStatut().
     */
    public function test_annuler_pendant_que_le_livreur_est_en_cours_signale_un_retour(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommande($this->creerCommercial());
        $commande->update(['statut_commande' => STATUT_COMMANDE_EN_LIVRAISON]);

        $livreurUser = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $livreurUser->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $livreurUser->id]);
        $commande->loadMissing('livraison')->livraison->update([
            'livreur_id' => $livreurUser->id,
            'statut_livraison' => STATUT_LIVRAISON_EN_COURS,
        ]);

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/statut", [
            'statut_commande' => STATUT_COMMANDE_ANNULEE,
        ])->assertOk();

        $livraison = $commande->livraison->fresh();
        $this->assertSame(STATUT_LIVRAISON_ECHOUEE, $livraison->statut_livraison);
        $this->assertTrue($livraison->retour_necessaire);
        $this->assertSame(STATUT_RETOUR_LIVRAISON_EN_COURS, $livraison->statut_retour);
    }

    /**
     * Annulation AVANT toute assignation de livreur (pas de livraison en
     * cours) : aucun retour à signaler, comportement inchangé.
     */
    public function test_annuler_sans_livreur_assigne_ne_signale_aucun_retour(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommande($this->creerCommercial());

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/statut", [
            'statut_commande' => STATUT_COMMANDE_ANNULEE,
        ])->assertOk();

        $livraison = $commande->fresh('livraison')->livraison;
        $this->assertFalse((bool) $livraison->retour_necessaire);
        $this->assertNull($livraison->statut_retour);
    }

    public function test_en_livraison_est_rejete_il_faut_passer_par_assigner_livreur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommande($this->creerCommercial());

        $this->actingAs($coordinateur)->postJson("/api/v1/commandes/{$commande->id}/statut", [
            'statut_commande' => STATUT_COMMANDE_EN_LIVRAISON,
        ])->assertUnprocessable();
    }

    public function test_un_commercial_ne_peut_pas_changer_le_statut_librement(): void
    {
        $commercial = $this->creerCommercial();
        $commande = $this->creerCommande($commercial);

        $this->actingAs($commercial)->postJson("/api/v1/commandes/{$commande->id}/statut", [
            'statut_commande' => STATUT_COMMANDE_ANNULEE,
        ])->assertForbidden();
    }

    public function test_show_expose_un_apercu_vivant_pour_le_coordinateur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commande = $this->creerCommande($this->creerCommercial());

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/commandes/{$commande->id}");

        $reponse->assertOk();
        $apercu = $reponse->json('meta.apercu');
        $this->assertNotNull($apercu);
        $this->assertEquals((float) $commande->montant_total, $apercu['prix_produit']);
        $this->assertEquals($commande->montantNet(), $apercu['total']);
    }

    public function test_get_livreurs_liste_les_livreurs_actifs(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $livreur = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $livreur->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $livreur->id]);

        $reponse = $this->actingAs($coordinateur)->getJson('/api/v1/livreurs');

        $reponse->assertOk();
        $this->assertTrue(collect($reponse->json('data'))->contains('id', $livreur->id));
    }

    public function test_un_commercial_ne_peut_pas_lister_les_livreurs(): void
    {
        $commercial = $this->creerCommercial();

        $this->actingAs($commercial)->getJson('/api/v1/livreurs')->assertForbidden();
    }
}
