<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\ConsultationCommande;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class FournisseurCommandesEtProduitsTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_liste_des_produits_scopee_au_bon_fournisseur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produitA = $this->creerProduitPhysique();
        $produitB = $this->creerProduitPhysique();

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$produitA->fournisseur_id}/produits");

        $reponse->assertOk();
        $ids = collect($reponse->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($produitA->id));
        $this->assertFalse($ids->contains($produitB->id));
    }

    public function test_liste_des_commandes_scopee_au_bon_fournisseur_et_filtrable_par_statut(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produitA = $this->creerProduitPhysique();
        $produitB = $this->creerProduitPhysique();

        $clientA = $this->creerClient();
        $adresseA = $this->creerAdresseAvecLocalite($clientA);
        $commandeA = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $clientA->id, 'adresse_id' => $adresseA->id,
            'lignes' => [['produit_id' => $produitA->id, 'quantite' => 1]],
        ]);
        Commande::findOrFail($commandeA->json('data.id'))->update(['statut_commande' => STATUT_COMMANDE_LIVREE]);

        $clientB = $this->creerClient();
        $adresseB = $this->creerAdresseAvecLocalite($clientB);
        $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $clientB->id, 'adresse_id' => $adresseB->id,
            'lignes' => [['produit_id' => $produitB->id, 'quantite' => 1]],
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$produitA->fournisseur_id}/commandes");
        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data.commandes.data'));
        $this->assertSame($produitA->nom_produit, $reponse->json('data.commandes.data')[0]['nom_produit']);

        $filtree = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$produitA->fournisseur_id}/commandes?statut=".STATUT_COMMANDE_EN_ATTENTE);
        $filtree->assertOk();
        $this->assertCount(0, $filtree->json('data.commandes.data'));
    }

    public function test_les_compteurs_regroupent_les_9_statuts_en_5_buckets(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();

        $creerCommande = function (string $statut) use ($commercial, $produit) {
            $client = $this->creerClient();
            $adresse = $this->creerAdresseAvecLocalite($client);
            $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
                'client_id' => $client->id, 'adresse_id' => $adresse->id,
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            ]);
            Commande::findOrFail($reponse->json('data.id'))->update(['statut_commande' => $statut]);
        };

        $creerCommande(STATUT_COMMANDE_EN_ATTENTE);
        $creerCommande(STATUT_COMMANDE_VALIDEE);
        $creerCommande(STATUT_COMMANDE_EN_PREPARATION);
        $creerCommande(STATUT_COMMANDE_EN_LIVRAISON);
        $creerCommande(STATUT_COMMANDE_REPORTEE);
        $creerCommande(STATUT_COMMANDE_CLIENT_INJOIGNABLE);
        $creerCommande(STATUT_COMMANDE_LIVREE);
        $creerCommande(STATUT_COMMANDE_LIVREE);
        $creerCommande(STATUT_COMMANDE_ANNULEE);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}/commandes");

        $reponse->assertOk();
        $reponse->assertJsonPath('data.compteurs.nouvelle', 1);
        $reponse->assertJsonPath('data.compteurs.en_cours', 3);
        $reponse->assertJsonPath('data.compteurs.attention', 2);
        $reponse->assertJsonPath('data.compteurs.livree', 2);
        $reponse->assertJsonPath('data.compteurs.annulee', 1);
    }

    public function test_le_badge_nouvelles_activites_compte_les_messages_non_lus_du_coordinateur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $produit = $this->creerProduitPhysique();

        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);
        $reponseCommande = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id, 'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commandeId = $reponseCommande->json('data.id');

        // La création de commande publie déjà un message système ("commande
        // créée", auteur = commercial) — donc 1 non-lu pour le coordinateur
        // avant même le message client ci-dessous.
        $avantMessageClient = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}/commandes");
        $this->assertSame(1, $avantMessageClient->json('data.commandes.data')[0]['nouvelles_activites']);

        Message::create([
            'produit_id' => $produit->id,
            'commande_id' => $commandeId,
            'auteur_id' => $client->id,
            'type' => TYPE_MESSAGE_TEXTE,
            'contenu' => 'Bonjour, où en est ma commande ?',
            'date_envoi' => now(),
        ]);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}/commandes");
        $reponse->assertOk();
        $this->assertSame(2, $reponse->json('data.commandes.data')[0]['nouvelles_activites']);

        ConsultationCommande::create(['user_id' => $coordinateur->id, 'commande_id' => $commandeId, 'consulte_le' => now()]);

        $apresConsultation = $this->actingAs($coordinateur)->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}/commandes");
        $this->assertSame(0, $apresConsultation->json('data.commandes.data')[0]['nouvelles_activites']);
    }

    public function test_un_fournisseur_ne_peut_pas_consulter_ces_endpoints(): void
    {
        $produit = $this->creerProduitPhysique();
        $autreFournisseur = $this->creerFournisseur();

        $this->actingAs($autreFournisseur)->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}/produits")->assertForbidden();
        $this->actingAs($autreFournisseur)->getJson("/api/v1/fournisseurs/{$produit->fournisseur_id}/commandes")->assertForbidden();
    }
}
