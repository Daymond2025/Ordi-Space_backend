<?php

namespace Tests\Feature;

use App\Models\Adresse;
use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\LigneCommande;
use App\Models\Livraison;
use App\Models\Livreur;
use App\Models\Localite;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * POST /livraisons/{livraison}/accepter — le livreur accepte une mission
 * assignée par le coordinateur (statut 'assignee', voir
 * CommandeController::assignerLivreur()).
 */
class LivraisonAccepterTest extends TestCase
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

    private function creerLivraison(?int $livreurId, string $statutLivraison): Livraison
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
        ]);

        return Livraison::create([
            'commande_id' => $commande->id,
            'livreur_id' => $livreurId,
            'adresse_id' => $adresse->id,
            'statut_livraison' => $statutLivraison,
        ]);
    }

    public function test_le_livreur_accepte_sa_mission_assignee(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_ASSIGNEE);

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/accepter");

        $reponse->assertOk();
        $livraison->refresh();
        $this->assertSame(STATUT_LIVRAISON_EN_COURS, $livraison->statut_livraison);
        $this->assertNotNull($livraison->date_prise_en_charge);
        $this->assertDatabaseHas('notifications_ordispace', [
            'user_id' => $livreur->id,
            'type_notification' => 'mission_acceptee',
        ]);
    }

    public function test_un_autre_livreur_ne_peut_pas_accepter(): void
    {
        $livreur = $this->creerLivreur();
        $autreLivreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_ASSIGNEE);

        $this->actingAs($autreLivreur)->postJson("/api/v1/livraisons/{$livraison->id}/accepter")->assertForbidden();
    }

    public function test_une_mission_deja_en_cours_ne_peut_pas_etre_re_acceptee(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($livreur->id, STATUT_LIVRAISON_EN_COURS);

        $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/accepter")->assertStatus(422);
    }

    public function test_le_livreur_prend_en_charge_une_mission_du_vivier(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison(null, STATUT_LIVRAISON_EN_ATTENTE_LIVREUR);

        $reponse = $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/affecter");

        $reponse->assertOk();
        $livraison->refresh();
        $this->assertSame($livreur->id, $livraison->livreur_id);
        $this->assertSame(STATUT_LIVRAISON_EN_COURS, $livraison->statut_livraison);
        $this->assertDatabaseHas('notifications_ordispace', [
            'user_id' => $livreur->id,
            'type_notification' => 'mission_acceptee',
        ]);
    }

    /**
     * Régression : une livraison fraîchement créée (statut "en_preparation",
     * livreur_id null — voir CommandeController::store()) n'est pas encore
     * publiée au vivier par le coordinateur. affecter() ne vérifiait que
     * livreur_id, pas statut_livraison : n'importe quel livreur pouvait
     * s'emparer d'une commande pas encore prête en devinant son id.
     */
    public function test_un_livreur_ne_peut_pas_prendre_en_charge_une_livraison_pas_encore_publiee_au_vivier(): void
    {
        $livreur = $this->creerLivreur();
        $livraison = $this->creerLivraison(null, STATUT_LIVRAISON_EN_PREPARATION);

        $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/affecter")->assertStatus(422);
        $this->assertNull($livraison->fresh()->livreur_id);
    }

    public function test_on_ne_peut_pas_affecter_une_livraison_deja_prise(): void
    {
        $livreur = $this->creerLivreur();
        $autreLivreur = $this->creerLivreur();
        $livraison = $this->creerLivraison($autreLivreur->id, STATUT_LIVRAISON_EN_ATTENTE_LIVREUR);

        $this->actingAs($livreur)->postJson("/api/v1/livraisons/{$livraison->id}/affecter")->assertStatus(422);
    }
}
