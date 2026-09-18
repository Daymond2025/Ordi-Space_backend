<?php   

namespace Tests\Feature;

use App\Models\Adresse;
use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\LigneCommande;
use App\Models\Livraison;
use App\Models\Livreur;
use App\Models\Localite;
use App\Models\Paiement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * App Livreur — enrichissement de LivraisonController (index/show/recuperer),    
 * upload réel de la preuve de livraison, et MoiController::paiements().
 */
class LivraisonEnrichieTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerLivreur(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id]);

        return $user;
    }

    private function creerLivraison(?int $livreurId, string $statutLivraison, ?string $lienMaps = null): Livraison
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();
        $commercial = $this->creerAgentIa();
        $canal = CanalVente::firstOrCreate(['nom_canal' => 'Boutique en ligne']);
        $localite = Localite::firstOrCreate(['nom' => 'Cocody'], ['type' => 'commune_abidjan']);

        $commande = Commande::create([
            'client_id' => $client->id,
            'commercial_id' => $commercial->id,
            'canal_vente_id' => $canal->id,
            'statut_commande' => STATUT_COMMANDE_EN_LIVRAISON,
            'montant_total' => $produit->prix,
            'date_commande' => now(),
        ]);

        LigneCommande::create([
            'commande_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 1,
            'prix_unitaire' => $produit->prix,
        ]);

        $adresse = Adresse::create([
            'client_id' => $client->id,
            'rue' => 'Rue Test',
            'ville' => 'Abidjan',
            'pays' => "Côte d'Ivoire",
            'localite_id' => $localite->id,
            'lien_maps' => $lienMaps,
        ]);

        return Livraison::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreurId,
            'adresse_id' => $adresse->id,
            'statut_livraison' => $statutLivraison,
        ]);
    }

    public function test_index_renvoie_une_forme_enrichie_avec_le_lien_maps_de_destination(): void
    {
        $livreur = $this->creerLivreur();
        $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS, 'https://maps.google.com/?q=1,2');

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/livraisons');

        $reponse->assertOk();
        $mission = $reponse->json('data.data.0');
        $this->assertSame('https://maps.google.com/?q=1,2', $mission['lien_maps_destination']);
        $this->assertArrayHasKey('nom_produit', $mission);
        $this->assertArrayHasKey('nom_client', $mission);
        $this->assertArrayHasKey('telephone_client', $mission);
        $this->assertArrayHasKey('nom_fournisseur', $mission);
        $this->assertArrayHasKey('colis_recupere_le', $mission);
    }

    public function test_le_livreur_marque_le_colis_recupere_chez_le_fournisseur(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/recuperer");

        $reponse->assertOk();
        $this->assertNotNull($livraison->fresh()->colis_recupere_le);
    }

    public function test_un_autre_livreur_ne_peut_pas_marquer_le_colis_recupere(): void
    {
        $livreur = $this->creerLivreur();
        $autreLivreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        $this->actingAs($autreLivreur)->postJson("/api/v1/livraisons/{$livraison->id}/recuperer")->assertForbidden();
    }

    public function test_on_ne_peut_pas_marquer_recupere_une_livraison_pas_encore_en_cours(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_ASSIGNEE);

        $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/recuperer")->assertStatus(422);
    }

    public function test_le_livreur_demarre_la_livraison_apres_recuperation(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);
        $livraison->update(['colis_recupere_le' => now()]);

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/demarrer-livraison");

        $reponse->assertOk();
        $this->assertNotNull($livraison->fresh()->livraison_demarree_le);
    }

    public function test_on_ne_peut_pas_demarrer_la_livraison_sans_avoir_recupere_le_colis(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/demarrer-livraison")->assertStatus(422);
    }

    public function test_un_autre_livreur_ne_peut_pas_demarrer_la_livraison(): void
    {
        $livreur = $this->creerLivreur();
        $autreLivreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);
        $livraison->update(['colis_recupere_le' => now()]);

        $this->actingAs($autreLivreur)->postJson("/api/v1/livraisons/{$livraison->id}/demarrer-livraison")->assertForbidden();
    }

    public function test_le_livreur_confirme_etre_arrive_chez_le_client(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);
        $livraison->update(['colis_recupere_le' => now(), 'livraison_demarree_le' => now()]);

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/arriver");

        $reponse->assertOk();
        $this->assertNotNull($livraison->fresh()->arrivee_le);
    }

    public function test_on_ne_peut_pas_confirmer_larrivee_sans_avoir_demarre_la_livraison(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);
        $livraison->update(['colis_recupere_le' => now()]);

        $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/arriver")->assertStatus(422);
    }

    public function test_un_autre_livreur_ne_peut_pas_confirmer_larrivee(): void
    {
        $livreur = $this->creerLivreur();
        $autreLivreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);
        $livraison->update(['colis_recupere_le' => now(), 'livraison_demarree_le' => now()]);

        $this->actingAs($autreLivreur)->postJson("/api/v1/livraisons/{$livraison->id}/arriver")->assertForbidden();
    }

    public function test_le_livreur_annule_la_commande_avec_un_motif(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);
        $produit = $livraison->commande->lignes->first()->produit;
        $stockAvant = $produit->quantite_stock;

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/annuler", [
            'motif' => 'Client injoignable',
        ]);

        $reponse->assertOk();
        $livraison->refresh();
        $this->assertSame(STATUT_LIVRAISON_ECHOUEE, $livraison->statut_livraison);
        $this->assertTrue($livraison->retour_necessaire);
        $this->assertSame(STATUT_RETOUR_LIVRAISON_EN_COURS, $livraison->statut_retour);
        $this->assertSame(STATUT_COMMANDE_ANNULEE, $livraison->commande->fresh()->statut_commande);
        $this->assertEquals($stockAvant + 1, $produit->fresh()->quantite_stock);
    }

    public function test_on_ne_peut_pas_annuler_sans_motif(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/annuler", [])->assertUnprocessable();
    }

    public function test_un_autre_livreur_ne_peut_pas_annuler(): void
    {
        $livreur = $this->creerLivreur();
        $autreLivreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        $this->actingAs($autreLivreur)
            ->postJson("/api/v1/livraisons/{$livraison->id}/annuler", ['motif' => 'Client injoignable'])
            ->assertForbidden();
    }

    public function test_le_livreur_confirme_le_colis_retourne_apres_annulation(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);
        $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/annuler", ['motif' => 'Client injoignable']);

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/retourner");

        $reponse->assertOk();
        $this->assertSame(STATUT_RETOUR_LIVRAISON_EFFECTUE, $livraison->fresh()->statut_retour);
    }

    public function test_on_ne_peut_pas_confirmer_le_retour_sans_annulation_prealable(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/retourner")->assertStatus(422);
    }

    public function test_le_livreur_livre_avec_une_vraie_photo_de_preuve(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/livrer", [
            'preuve_livraison' => UploadedFile::fake()->create('preuve.jpg', 100, 'image/jpeg'),
        ]);

        $reponse->assertOk();
        $livraison->refresh();
        $this->assertSame(STATUT_LIVRAISON_LIVREE, $livraison->statut_livraison);
        $this->assertNotNull($livraison->getRawOriginal('preuve_livraison'));
        $this->assertDatabaseHas('notifications_ordispace', [
            'user_id' => $livreur->id,
            'type_notification' => 'livraison_validee',
        ]);
    }

    public function test_livrer_rejette_un_fichier_qui_n_est_pas_une_image(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/livrer", [
            'preuve_livraison' => UploadedFile::fake()->create('preuve.jpg', 10, 'text/plain'),
        ]);

        $reponse->assertUnprocessable();
    }

    public function test_moi_paiements_agrege_les_gains_du_livreur_sur_ses_missions_livrees(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS, 'https://maps.google.com/?q=1,2');
        $livraison->update(['date_prise_en_charge' => now()]);
        $livraison->commande()->update(['frais_livraison' => 1500]);

        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$livraison->commande_id}/paiement", [
            'mode_paiement' => 'especes',
        ])->assertCreated();

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/moi/paiements?periode=semaine');

        $reponse->assertOk();
        // solde_total = cumul des frais_livraison des missions livrées, pas
        // l'encaissement client (Paiement::montant, une notion différente).
        $this->assertEquals(1500.0, $reponse->json('data.solde_total'));
        $this->assertEquals(1500.0, $reponse->json('data.gains_periode'));
        $paiement = Paiement::where('commande_id', $livraison->commande_id)->firstOrFail();
        $this->assertNotNull($reponse->json('data.date_limite_depot_urgente'));
        $this->assertEqualsWithDelta(
            $paiement->date_limite_depot->timestamp,
            \Illuminate\Support\Carbon::parse($reponse->json('data.date_limite_depot_urgente'))->timestamp,
            2,
        );
        $this->assertSame(1, $reponse->json('data.missions_recues'));
        $this->assertSame(1, $reponse->json('data.missions_validees'));
        $this->assertCount(1, $reponse->json('data.missions.data'));
        $this->assertEquals(1500, $reponse->json('data.missions.data.0.commande.frais_livraison'));
        $this->assertNotNull($reponse->json('data.missions.data.0.adresse.localite'));
        $this->assertNotNull($reponse->json('data.missions.data.0.commande.lignes.0.produit.fournisseur'));
    }

    public function test_moi_paiements_periode_filtre_les_compteurs_mais_pas_le_solde_total(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);
        $livraison->commande()->update(['frais_livraison' => 1500]);
        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$livraison->commande_id}/paiement", [
            'mode_paiement' => 'especes',
        ])->assertCreated();

        $livraison->update(['date_livraison_effective' => now()->subMonth()]);

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/moi/paiements?periode=semaine');

        $reponse->assertOk();
        $this->assertSame(0, $reponse->json('data.missions_validees'));
        $this->assertEquals(0, $reponse->json('data.gains_periode'));
        // solde_total ("SOLDES") est un cumul total, jamais filtré par période.
        $this->assertEquals(1500.0, $reponse->json('data.solde_total'));
    }

    public function test_moi_paiements_reverser_depose_tous_les_paiements_en_attente_dun_coup(): void
    {
        $livreur = $this->creerLivreur();
        $livraisonA = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);
        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$livraisonA->commande_id}/paiement", ['mode_paiement' => 'especes'])->assertCreated();
        $livraisonB = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);
        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$livraisonB->commande_id}/paiement", ['mode_paiement' => 'especes'])->assertCreated();

        $reponse = $this->actingAs($livreur)->postJson('/api/v1/moi/paiements/reverser');

        $reponse->assertOk();
        $this->assertSame(2, $reponse->json('data.nombre_reverse'));
        $this->assertSame(0, Paiement::where('livreur_id', $livreur->id)->whereNull('date_depot')->count());

        $verif = $this->actingAs($livreur)->getJson('/api/v1/moi/paiements');
        $this->assertEquals(0, $verif->json('data.gains_non_deposes'));
    }

    public function test_moi_paiements_reverser_est_reserve_au_livreur(): void
    {
        $client = $this->creerClient();

        $this->actingAs($client)->postJson('/api/v1/moi/paiements/reverser')->assertForbidden();
    }

    public function test_moi_paiements_est_reserve_au_livreur(): void
    {
        $client = $this->creerClient();

        $this->actingAs($client)->getJson('/api/v1/moi/paiements')->assertForbidden();
    }

    public function test_le_paiement_espece_marque_automatiquement_la_livraison_comme_livree(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$livraison->commande_id}/paiement", [
            'mode_paiement' => 'especes',
        ])->assertCreated();

        $livraison->refresh();
        $this->assertSame(STATUT_LIVRAISON_LIVREE, $livraison->statut_livraison);
        $this->assertNotNull($livraison->date_livraison_effective);
        $this->assertSame(STATUT_COMMANDE_LIVREE, $livraison->commande->fresh()->statut_commande);
    }

    public function test_moi_recapitulatif_jour_agrege_les_livraisons_et_revenus_du_jour(): void
    {
        $livreur = $this->creerLivreur();

        $livree1 = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_LIVREE);
        $livree1->commande()->update(['frais_livraison' => 1500]);
        $livree1->update(['date_livraison_effective' => now()]);

        $livree2 = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_LIVREE);
        $livree2->commande()->update(['frais_livraison' => 1000]);
        $livree2->update(['date_livraison_effective' => now()]);

        // Livrée hier : ne doit pas compter dans le récapitulatif du jour.
        $livreeHier = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_LIVREE);
        $livreeHier->commande()->update(['frais_livraison' => 2000]);
        $livreeHier->update(['date_livraison_effective' => now()->subDay()]);

        // En cours (pas encore livrée) : ne doit pas compter non plus.
        $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        $reponse = $this->actingAs($livreur)->getJson('/api/v1/moi/recapitulatif-jour');

        $reponse->assertOk();
        $this->assertSame(2, $reponse->json('data.livraisons_du_jour'));
        $this->assertEquals(2500.0, $reponse->json('data.revenu_du_jour'));
    }

    public function test_moi_recapitulatif_jour_est_reserve_au_livreur(): void
    {
        $client = $this->creerClient();

        $this->actingAs($client)->getJson('/api/v1/moi/recapitulatif-jour')->assertForbidden();
    }
}
