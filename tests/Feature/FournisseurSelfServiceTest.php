<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Espace Fournisseur en libre-service (app Fournisseur) —
 * routes/api.php prefix('fournisseur')/moi/... — mêmes vues que
 * fournisseurs/{fournisseur}/... (Centre des opérations Coordinateur,
 * voir FournisseurCommandesEtProduitsTest/FournisseurPortefeuilleTest/
 * FournisseurStatistiquesTest), simplement rejouées sur le fournisseur du
 * jeton courant. On ne re-teste pas ici le détail de leur contenu (déjà
 * couvert par ces classes), seulement le bon scopage + la fiche profil.
 */
class FournisseurSelfServiceTest extends TestCase
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
        Livreur::create(['user_id' => $user->id, 'disponible' => false, 'type_vehicule' => 'moto']);

        return $user;
    }

    public function test_moi_detail_renvoie_bien_le_fournisseur_du_jeton(): void
    {
        $fournisseur = $this->creerFournisseur(['nom' => 'Ma Boutique']);

        $reponse = $this->actingAs($fournisseur)->getJson('/api/v1/fournisseur/moi');

        $reponse->assertOk();
        $this->assertSame($fournisseur->id, $reponse->json('data.fournisseur.user_id'));
    }

    public function test_moi_produits_ne_montre_que_les_produits_du_fournisseur_connecte(): void
    {
        $produitA = $this->creerProduitPhysique();
        $fournisseurA = User::find($produitA->fournisseur_id);
        $produitB = $this->creerProduitPhysique();

        $reponse = $this->actingAs($fournisseurA)->getJson('/api/v1/fournisseur/moi/produits');

        $reponse->assertOk();
        $ids = collect($reponse->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($produitA->id));
        $this->assertFalse($ids->contains($produitB->id));
    }

    public function test_moi_commandes_ne_montre_que_les_commandes_du_fournisseur_connecte(): void
    {
        $commercial = $this->creerCommercial();
        $produitA = $this->creerProduitPhysique();
        $fournisseurA = User::find($produitA->fournisseur_id);
        $produitB = $this->creerProduitPhysique();

        $clientA = $this->creerClient();
        $adresseA = $this->creerAdresseAvecLocalite($clientA);
        $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $clientA->id, 'adresse_id' => $adresseA->id,
            'lignes' => [['produit_id' => $produitA->id, 'quantite' => 1]],
        ]);

        $clientB = $this->creerClient();
        $adresseB = $this->creerAdresseAvecLocalite($clientB);
        $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $clientB->id, 'adresse_id' => $adresseB->id,
            'lignes' => [['produit_id' => $produitB->id, 'quantite' => 1]],
        ]);

        $reponse = $this->actingAs($fournisseurA)->getJson('/api/v1/fournisseur/moi/commandes');

        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data.commandes.data'));
        $this->assertSame($produitA->nom_produit, $reponse->json('data.commandes.data')[0]['nom_produit']);
    }

    public function test_moi_portefeuille_et_statistiques_repondent_200_pour_le_fournisseur_connecte(): void
    {
        $fournisseur = $this->creerFournisseur();

        $this->actingAs($fournisseur)->getJson('/api/v1/fournisseur/moi/portefeuille')->assertOk();
        $this->actingAs($fournisseur)->getJson('/api/v1/fournisseur/moi/statistiques')->assertOk();
    }

    public function test_un_autre_role_ne_peut_pas_acceder_aux_routes_moi_fournisseur(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $livreur = $this->creerLivreur();

        $this->actingAs($coordinateur)->getJson('/api/v1/fournisseur/moi')->assertForbidden();
        $this->actingAs($livreur)->getJson('/api/v1/fournisseur/moi')->assertForbidden();
    }

    public function test_patch_moi_profil_met_a_jour_les_champs_autorises(): void
    {
        $fournisseur = $this->creerFournisseur();

        $reponse = $this->actingAs($fournisseur)->patchJson('/api/v1/fournisseur/moi/profil', [
            'adresse_entreprise' => 'Cocody, Rue des Jardins',
            'contact_pro' => 'contact@boutique.test',
            'nom_gerant' => 'Jean Gérant',
            'telephone_gerant' => '0700000000',
            'horaires_ouverture' => 'Lun-Sam 8h-18h',
            'lien_maps' => 'https://maps.google.com/?q=1',
            'zone_couverte' => 'Abidjan',
        ]);

        $reponse->assertOk();
        $reponse->assertJsonPath('data.adresse_entreprise', 'Cocody, Rue des Jardins');
        $reponse->assertJsonPath('data.contact_pro', 'contact@boutique.test');
        $reponse->assertJsonPath('data.nom_gerant', 'Jean Gérant');
        $reponse->assertJsonPath('data.telephone_gerant', '0700000000');
        $reponse->assertJsonPath('data.horaires_ouverture', 'Lun-Sam 8h-18h');
        $reponse->assertJsonPath('data.lien_maps', 'https://maps.google.com/?q=1');
        $reponse->assertJsonPath('data.zone_couverte', 'Abidjan');
    }

    public function test_patch_moi_profil_ignore_les_champs_proteges(): void
    {
        $fournisseur = $this->creerFournisseur();
        $nomOriginal = $fournisseur->fournisseur->nom_entreprise;

        $this->actingAs($fournisseur)->patchJson('/api/v1/fournisseur/moi/profil', [
            'nom_entreprise' => 'Nom Usurpé',
            'taux_commission' => 99,
            'solde_portefeuille' => 999999,
            'adresse_entreprise' => 'Adresse légitime',
        ])->assertOk();

        $fournisseur->fournisseur->refresh();
        $this->assertSame($nomOriginal, $fournisseur->fournisseur->nom_entreprise);
        $this->assertNotEquals(99, $fournisseur->fournisseur->taux_commission);
        $this->assertNotEquals(999999, (float) $fournisseur->fournisseur->solde_portefeuille);
        $this->assertSame('Adresse légitime', $fournisseur->fournisseur->adresse_entreprise);
    }

    public function test_moi_profil_reflete_les_champs_fournisseur_apres_modification(): void
    {
        $fournisseur = $this->creerFournisseur();

        $this->actingAs($fournisseur)->patchJson('/api/v1/fournisseur/moi/profil', [
            'zone_couverte' => 'Yopougon',
        ])->assertOk();

        $reponse = $this->actingAs($fournisseur)->getJson('/api/v1/moi/profil');

        $reponse->assertOk();
        $reponse->assertJsonPath('data.zone_couverte', 'Yopougon');
        $this->assertArrayHasKey('nom_entreprise', $reponse->json('data'));
        $this->assertArrayHasKey('taux_commission', $reponse->json('data'));
        $this->assertArrayHasKey('solde_portefeuille', $reponse->json('data'));
    }
}
