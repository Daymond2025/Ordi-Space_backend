<?php

namespace Tests\Feature;

use App\Models\Garantie;
use App\Models\LigneCommande;
use App\Models\Livraison;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Écran Admin Web "Nouvelle commande" (/clients/nouveau) —
 * CommandeController::store() : frais de livraison et durée de garantie
 * négociés par l'Admin (même garde que le prix négocié, voir
 * CommandePrixAdminTest), date de livraison prévue renseignée pour la
 * première fois (colonne livraisons.date_livraison_prevue jusqu'ici morte),
 * puis assignation d'un livreur via l'endpoint existant
 * CommandeController::assignerLivreur().
 */
class CommandeAdminEnrichieTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_un_admin_peut_fournir_frais_livraison_date_prevue_et_duree_garantie(): void
    {
        $admin = $this->creerAdmin();
        $client = $this->creerClient();
        // duree_garantie_mois par défaut du produit (12) — doit être ignorée
        // au profit de la surcharge envoyée (6).
        $produit = $this->creerProduitPhysique(['duree_garantie_mois' => 12]);
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($admin)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'duree_garantie_mois' => 6]],
            'frais_livraison' => 3500,
            'date_livraison_prevue' => '2026-10-20 14:30:00',
        ]);

        $reponse->assertCreated();
        $commandeId = $reponse->json('data.id');

        $this->assertEquals(3500, $reponse->json('data.frais_livraison'));

        $livraison = Livraison::where('commande_id', $commandeId)->firstOrFail();
        $this->assertSame('2026-10-20 14:30:00', $livraison->date_livraison_prevue->format('Y-m-d H:i:s'));

        $ligne = LigneCommande::where('commande_id', $commandeId)->firstOrFail();
        $this->assertSame(6, $ligne->duree_garantie_mois);
    }

    public function test_ces_surcharges_sont_ignorees_pour_un_role_non_admin(): void
    {
        $commercial = $this->creerCommercial();
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique(['duree_garantie_mois' => 12]);
        $adresse = $this->creerAdresseAvecLocalite($client);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'duree_garantie_mois' => 6]],
            'frais_livraison' => 1,
        ]);

        $reponse->assertCreated();
        $commandeId = $reponse->json('data.id');

        // Le barème automatique prévaut (2000, voir creerProduitPhysique()),
        // pas le "1" envoyé.
        $this->assertEquals(2000, $reponse->json('data.frais_livraison'));

        $ligne = LigneCommande::where('commande_id', $commandeId)->firstOrFail();
        $this->assertNull($ligne->duree_garantie_mois);
    }

    public function test_assigner_un_livreur_juste_apres_la_creation_fonctionne_sans_contrainte_de_statut(): void
    {
        $admin = $this->creerAdmin();
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique();
        $adresse = $this->creerAdresseAvecLocalite($client);
        $livreur = $this->creerLivreur();

        $commande = $this->actingAs($admin)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ])->json('data');

        $reponse = $this->actingAs($admin)->postJson("/api/v1/commandes/{$commande['id']}/assigner-livreur", [
            'livreur_id' => $livreur->id,
        ]);

        $reponse->assertOk();
        $this->assertSame('en_livraison', $reponse->json('data.statut_commande'));
        $this->assertEquals($livreur->id, Livraison::where('commande_id', $commande['id'])->value('livreur_id'));
    }

    public function test_la_garantie_generee_a_la_livraison_utilise_la_duree_surchargee(): void
    {
        $admin = $this->creerAdmin();
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique(['duree_garantie_mois' => 12]);
        $adresse = $this->creerAdresseAvecLocalite($client);

        $commandeId = $this->actingAs($admin)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'duree_garantie_mois' => 6]],
        ])->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commandeId}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        $ligne = LigneCommande::where('commande_id', $commandeId)->firstOrFail();
        $garantie = Garantie::where('ligne_commande_id', $ligne->id)->firstOrFail();

        $this->assertSame(
            $garantie->date_debut->copy()->addMonths(6)->toDateString(),
            $garantie->date_fin->toDateString()
        );
    }

    public function test_une_duree_courte_en_jours_est_honoree_a_la_place_du_nombre_de_mois(): void
    {
        $admin = $this->creerAdmin();
        $client = $this->creerClient();
        // duree_garantie_mois par défaut du produit (12) — doit être ignorée
        // au profit des 14 jours (2 semaines) envoyés.
        $produit = $this->creerProduitPhysique(['duree_garantie_mois' => 12]);
        $adresse = $this->creerAdresseAvecLocalite($client);

        $commandeId = $this->actingAs($admin)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'duree_garantie_jours' => 14]],
        ])->json('data.id');

        $ligne = LigneCommande::where('commande_id', $commandeId)->firstOrFail();
        $this->assertSame(14, $ligne->duree_garantie_jours);
        $this->assertNull($ligne->duree_garantie_mois);

        $this->actingAs($admin)->patchJson("/api/v1/admin/commandes/{$commandeId}/statut", [
            'statut_commande' => 'livree',
        ])->assertOk();

        $garantie = Garantie::where('ligne_commande_id', $ligne->id)->firstOrFail();

        $this->assertSame(
            $garantie->date_debut->copy()->addDays(14)->toDateString(),
            $garantie->date_fin->toDateString()
        );
    }
}
