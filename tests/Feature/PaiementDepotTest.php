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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Reversement du cash COD encaissé par le livreur : date_limite_depot posée
 * à l'encaissement (PaiementController::encaisser()), date_depot posée par
 * le livreur via POST /paiements/{paiement}/deposer.
 */
class PaiementDepotTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommandeAvecLivreur(): array
    {
        $livreurUser = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $livreurUser->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $livreurUser->id]);

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
        ]);

        Livraison::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreurUser->id,
            'adresse_id' => $adresse->id,
            'statut_livraison' => STATUT_LIVRAISON_EN_COURS,
        ]);

        return [$commande->fresh('livraison'), $livreurUser];
    }

    public function test_encaisser_en_especes_pose_une_date_limite_de_depot(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/commandes/{$commande->id}/paiement", [
            'mode_paiement' => 'especes',
        ]);

        $reponse->assertCreated();
        $paiement = Paiement::where('commande_id', $commande->id)->firstOrFail();
        $this->assertNotNull($paiement->date_limite_depot);
        $this->assertNull($paiement->date_depot);
    }

    public function test_encaisser_en_mobile_money_ne_pose_pas_de_delai_de_depot(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();

        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$commande->id}/paiement", [
            'mode_paiement' => 'mobile_money',
        ])->assertCreated();

        $paiement = Paiement::where('commande_id', $commande->id)->firstOrFail();
        $this->assertNull($paiement->date_limite_depot);
    }

    public function test_le_livreur_confirme_son_depot(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$commande->id}/paiement", ['mode_paiement' => 'especes']);
        $paiement = Paiement::where('commande_id', $commande->id)->firstOrFail();

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/paiements/{$paiement->id}/deposer");

        $reponse->assertOk();
        $this->assertNotNull($paiement->fresh()->date_depot);
    }

    public function test_un_autre_livreur_ne_peut_pas_deposer_a_sa_place(): void
    {
        [$commande, $livreur] = $this->creerCommandeAvecLivreur();
        $this->actingAs($livreur)->postJson("/api/v1/commandes/{$commande->id}/paiement", ['mode_paiement' => 'especes']);
        $paiement = Paiement::where('commande_id', $commande->id)->firstOrFail();

        $autreLivreurUser = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $autreLivreurUser->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $autreLivreurUser->id]);

        $this->actingAs($autreLivreurUser)->postJson("/api/v1/paiements/{$paiement->id}/deposer")->assertForbidden();
    }
}
