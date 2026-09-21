<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Commande;
use App\Models\FraisLivraisonProduit;
use App\Models\Livraison;
use App\Models\Livreur;
use App\Models\Localite;
use App\Models\Privilege;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class FraisLivraisonTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_get_localites_liste_les_33_lignes_seedees_et_filtre_par_type(): void
    {
        $reponse = $this->getJson('/api/v1/localites');
        $reponse->assertOk();
        $this->assertCount(33, $reponse->json('data'));

        $communes = $this->getJson('/api/v1/localites?type='.TYPE_LOCALITE_COMMUNE_ABIDJAN);
        $communes->assertOk();
        $this->assertCount(13, $communes->json('data'));

        $villes = $this->getJson('/api/v1/localites?type='.TYPE_LOCALITE_VILLE);
        $villes->assertOk();
        $this->assertCount(20, $villes->json('data'));
    }

    public function test_ajouter_une_adresse_sans_localite_est_rejete_avec_est_cree(): void
    {
        $client = $this->creerClient();
        $localite = Localite::where('nom', 'Cocody')->firstOrFail();

        $this->actingAs($client)->postJson('/api/v1/moi/adresses', [
            'rue' => 'Rue Test', 'ville' => 'Abidjan', 'pays' => "Côte d'Ivoire",
        ])->assertUnprocessable();

        $reponse = $this->actingAs($client)->postJson('/api/v1/moi/adresses', [
            'rue' => 'Rue Test', 'ville' => 'Abidjan', 'pays' => "Côte d'Ivoire", 'localite_id' => $localite->id,
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('adresses', ['client_id' => $client->id, 'localite_id' => $localite->id]);
    }

    public function test_un_fournisseur_definit_un_bareme_a_la_creation_d_un_produit(): void
    {
        $fournisseur = $this->creerFournisseur();
        $categorie = Categorie::firstOrCreate(['nom_categorie' => 'Ordinateurs portables']);
        $cocody = Localite::where('nom', 'Cocody')->firstOrFail();
        $yopougon = Localite::where('nom', 'Yopougon')->firstOrFail();

        $reponse = $this->actingAs($fournisseur)->postJson('/api/v1/produits', [
            'categorie_id' => $categorie->id,
            'nom_produit' => 'Laptop Barème',
            'prix' => 300000,
            'quantite_stock' => 5,
            'type_livraison' => TYPE_LIVRAISON_PHYSIQUE,
            'frais_livraison' => [
                ['localite_id' => $cocody->id, 'montant' => 1500],
                ['localite_id' => $yopougon->id, 'montant' => 2500],
            ],
        ]);

        $reponse->assertCreated();
        $produitId = $reponse->json('data.id');

        $this->assertDatabaseHas('frais_livraison_produits', ['produit_id' => $produitId, 'localite_id' => $cocody->id, 'montant' => 1500]);
        $this->assertDatabaseHas('frais_livraison_produits', ['produit_id' => $produitId, 'localite_id' => $yopougon->id, 'montant' => 2500]);

        // Section "Livraison et Garantie" (fiche produit) — le barème doit
        // être lisible publiquement, même sans compte (ProduitController::show()).
        $ficheProduit = $this->getJson("/api/v1/produits/{$produitId}");
        $ficheProduit->assertOk();
        $this->assertCount(2, $ficheProduit->json('data.frais_livraison'));
        $this->assertSame('Cocody', $ficheProduit->json('data.frais_livraison.0.localite.nom'));
    }

    public function test_un_bareme_non_vide_sur_un_produit_numerique_est_rejete(): void
    {
        $fournisseur = $this->creerFournisseur();
        $categorie = Categorie::firstOrCreate(['nom_categorie' => 'Logiciels']);
        $cocody = Localite::where('nom', 'Cocody')->firstOrFail();

        $reponse = $this->actingAs($fournisseur)->postJson('/api/v1/produits', [
            'categorie_id' => $categorie->id,
            'nom_produit' => 'Licence Test',
            'prix' => 20000,
            'quantite_stock' => 100,
            'type_livraison' => TYPE_LIVRAISON_NUMERIQUE,
            'frais_livraison' => [['localite_id' => $cocody->id, 'montant' => 1500]],
        ]);

        $reponse->assertUnprocessable();
        $this->assertDatabaseMissing('produits', ['nom_produit' => 'Licence Test']);
    }

    public function test_une_mise_a_jour_du_bareme_remplace_l_ancien_sans_fusion(): void
    {
        $fournisseur = $this->creerFournisseur();
        $categorie = Categorie::firstOrCreate(['nom_categorie' => 'Ordinateurs portables']);
        $cocody = Localite::where('nom', 'Cocody')->firstOrFail();
        $yopougon = Localite::where('nom', 'Yopougon')->firstOrFail();
        $bouake = Localite::where('nom', 'Bouaké')->firstOrFail();

        $creation = $this->actingAs($fournisseur)->postJson('/api/v1/produits', [
            'categorie_id' => $categorie->id,
            'nom_produit' => 'Laptop Remplacement',
            'prix' => 300000,
            'quantite_stock' => 5,
            'type_livraison' => TYPE_LIVRAISON_PHYSIQUE,
            'frais_livraison' => [['localite_id' => $cocody->id, 'montant' => 1500]],
        ]);
        $produitId = $creation->json('data.id');

        $miseAJour = $this->actingAs($fournisseur)->putJson("/api/v1/produits/{$produitId}", [
            'categorie_id' => $categorie->id,
            'nom_produit' => 'Laptop Remplacement',
            'prix' => 300000,
            'quantite_stock' => 5,
            'type_livraison' => TYPE_LIVRAISON_PHYSIQUE,
            'frais_livraison' => [
                ['localite_id' => $yopougon->id, 'montant' => 3000],
                ['localite_id' => $bouake->id, 'montant' => 5000],
            ],
        ]);
        $miseAJour->assertOk();

        $this->assertDatabaseMissing('frais_livraison_produits', ['produit_id' => $produitId, 'localite_id' => $cocody->id]);
        $this->assertDatabaseHas('frais_livraison_produits', ['produit_id' => $produitId, 'localite_id' => $yopougon->id, 'montant' => 3000]);
        $this->assertDatabaseHas('frais_livraison_produits', ['produit_id' => $produitId, 'localite_id' => $bouake->id, 'montant' => 5000]);
        $this->assertSame(2, FraisLivraisonProduit::where('produit_id', $produitId)->count());
    }

    public function test_commande_vers_une_localite_non_couverte_est_bloquee_et_tout_est_annule(): void
    {
        // creerProduitPhysique() n'attache un tarif que pour "Cocody" —
        // Yopougon n'est volontairement pas couvert par ce produit.
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5]);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client, 'Yopougon');

        $reponse = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        $reponse->assertUnprocessable();
        $this->assertSame(5, $produit->fresh()->quantite_stock);
        $this->assertDatabaseMissing('commandes', ['client_id' => $client->id]);
    }

    public function test_commande_vers_une_localite_couverte_calcule_et_stocke_le_frais(): void
    {
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5, 'prix' => 100000]);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client, 'Cocody');

        $reponse = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('commandes', ['id' => $reponse->json('data.id'), 'frais_livraison' => 2000]);
    }

    public function test_la_livraison_gratuite_neutralise_le_frais_malgre_un_bareme_existant(): void
    {
        Privilege::create([
            'titre' => 'Carte free', 'type_privilege' => TYPE_PRIVILEGE_LIVRAISON_GRATUITE, 'actif' => true,
        ]);

        $produit = $this->creerProduitPhysique(['quantite_stock' => 10, 'prix' => 100000]);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client, 'Cocody');

        // Première commande : le seuil (2e commande) n'est pas encore atteint.
        $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ])->assertCreated();

        $seconde = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        $seconde->assertCreated();
        $this->assertDatabaseHas('commandes', [
            'id' => $seconde->json('data.id'), 'frais_livraison' => 0, 'livraison_gratuite_appliquee' => true,
        ]);
    }

    public function test_une_commande_100_pourcent_numerique_n_a_aucun_frais_ni_adresse_requise(): void
    {
        $produitNumerique = $this->creerProduitPhysique(['nom_produit' => 'Licence', 'type_livraison' => TYPE_LIVRAISON_NUMERIQUE]);
        $client = $this->creerClient();

        $reponse = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'lignes' => [['produit_id' => $produitNumerique->id, 'quantite' => 1]],
        ]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('commandes', ['id' => $reponse->json('data.id'), 'frais_livraison' => 0]);
    }

    public function test_l_encaissement_inclut_le_frais_de_livraison_dans_le_montant_reclame(): void
    {
        $produit = $this->creerProduitPhysique(['quantite_stock' => 5, 'prix' => 100000]);
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client, 'Cocody');

        $creation = $this->actingAs($client)->postJson('/api/v1/commandes', [
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commande = Commande::findOrFail($creation->json('data.id'));
        $this->assertEquals(2000, $commande->frais_livraison);

        $livreur = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        Livreur::create(['user_id' => $livreur->id]);
        Livraison::where('commande_id', $commande->id)->update(['livreur_id' => $livreur->id]);

        $paiement = $this->actingAs($livreur)->postJson("/api/v1/commandes/{$commande->id}/paiement", [
            'mode_paiement' => MODE_PAIEMENT_ESPECES,
        ]);

        $paiement->assertCreated();
        $this->assertEquals(102000, $paiement->json('data.montant'));
    }
}
