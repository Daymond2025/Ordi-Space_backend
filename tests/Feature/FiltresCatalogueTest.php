<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Produit;
use App\Services\CaracteristiquesProduit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Écran "Catégorie" de la Boutique (Livreur) : GET /categories/filtres alimente les
 * choix, GET /produits?… filtre par marque, processeur, RAM, stockage et taille.
 */
class FiltresCatalogueTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function produit(string $nom, array $attributs = []): Produit
    {
        return $this->creerProduitPhysique(array_merge(['nom_produit' => $nom, 'commission_revente' => 10000], $attributs));
    }

    /** @return array<int, string> noms des produits renvoyés, triés */
    private function noms(string $requete): array
    {
        $noms = collect($this->getJson('/api/v1/produits?per_page=50&'.$requete)->assertOk()->json('data.data'))->pluck('nom_produit')->all();
        sort($noms);

        return $noms;
    }

    public function test_les_caracteristiques_libres_sont_converties_en_nombres(): void
    {
        $this->assertSame(16, CaracteristiquesProduit::capaciteEnGo('16GB DDR4-2400'));
        $this->assertSame(512, CaracteristiquesProduit::capaciteEnGo('512 Go SSD'));
        $this->assertSame(1000, CaracteristiquesProduit::capaciteEnGo('1 To'));
        $this->assertSame(2000, CaracteristiquesProduit::capaciteEnGo('2TB HDD'));
        $this->assertNull(CaracteristiquesProduit::capaciteEnGo('inconnu'));
        $this->assertSame(14.0, CaracteristiquesProduit::taillePouces('14" Pouces'));
        $this->assertSame(15.6, CaracteristiquesProduit::taillePouces('15,6 pouces'));
    }

    public function test_le_produit_enregistre_ses_valeurs_numeriques_et_sa_marque(): void
    {
        $produit = $this->produit('Dell Latitude 5420', ['memoire_ram' => '16GB DDR4', 'stockage' => '1 To SSD', 'taille' => '14" Pouces'])->fresh();

        $this->assertSame('DELL', $produit->marque);
        $this->assertSame(16, $produit->ram_go);
        $this->assertSame(1000, $produit->stockage_go);
        $this->assertEquals(14.0, $produit->taille_pouces);

        $produit->update(['marque' => 'lenovo']);
        $this->assertSame('LENOVO', $produit->fresh()->marque);
    }

    public function test_les_choix_du_filtre_sont_groupes_par_famille(): void
    {
        $reponse = $this->getJson('/api/v1/categories/filtres')->assertOk();

        $this->assertSame(['Ordinateur portable', 'Ordinateur bureau'], collect($reponse->json('data.ordinateur.types'))->pluck('nom')->all());
        $this->assertContains('Souris', collect($reponse->json('data.accessoires.categories'))->pluck('nom')->all());
        $this->assertNotContains('Accessoires', collect($reponse->json('data.accessoires.categories'))->pluck('nom')->all());

        $groupes = collect($reponse->json('data.logiciels.groupes'));
        $this->assertSame(['Pack office', 'Navigateur', 'Spécial suite Adobe'], $groupes->pluck('titre')->all());
        $this->assertSame(['Word', 'Excel', 'Power Point', 'Publisher', 'Autre'], collect($groupes->first()['categories'])->pluck('nom')->all());
    }

    public function test_filtre_par_marque_avec_autre_pour_les_marques_hors_liste(): void
    {
        $this->produit('HP 840 G5');
        $this->produit('Dell Latitude');
        $this->produit('Acer Aspire', ['marque' => 'Acer']);
        $this->produit('Portable sans marque connue');

        $this->assertSame(['HP 840 G5'], $this->noms('marques[]=hp'));
        $this->assertSame(['Dell Latitude', 'HP 840 G5'], $this->noms('marques[]=hp&marques[]=dell'));
        $this->assertSame(['Acer Aspire', 'Portable sans marque connue'], $this->noms('marques[]=autre'));
        $this->assertSame(['Acer Aspire', 'HP 840 G5', 'Portable sans marque connue'], $this->noms('marques[]=hp&marques[]=autre'));
    }

    public function test_filtre_par_processeur_ram_stockage_et_taille(): void
    {
        $this->produit('A', ['processeur' => 'Intel Core i5-8365U', 'memoire_ram' => '8 Go', 'stockage' => '256 Go SSD', 'taille' => '14 pouces']);
        $this->produit('B', ['processeur' => 'Intel Core i7-1165G7', 'memoire_ram' => '16GB DDR4', 'stockage' => '1 To HDD', 'taille' => '15,6 pouces']);
        $this->produit('C', ['processeur' => 'Intel Celeron N4020', 'memoire_ram' => '4 Go', 'stockage' => '500 Go HDD', 'taille' => '11,6 pouces']);

        $this->assertSame(['A'], $this->noms('processeurs[]=Core i5'));
        $this->assertSame(['A', 'B'], $this->noms('processeurs[]=Core i5&processeurs[]=Core i7'));
        $this->assertSame(['C'], $this->noms('processeurs[]=Celeron'));

        $this->assertSame(['A'], $this->noms('rams[]=8'));
        $this->assertSame(['A', 'C'], $this->noms('rams[]=8&rams[]=4'));
        $this->assertSame(['B'], $this->noms('ram_min=16'));
        $this->assertSame(['B', 'C'], $this->noms('rams[]=4&ram_min=16'));

        $this->assertSame(['B', 'C'], $this->noms('stockage_min=500'));
        $this->assertSame(['B'], $this->noms('stockage_min=1000'));
        $this->assertSame(['A'], $this->noms('stockage_type=ssd'));
        $this->assertSame(['B', 'C'], $this->noms('stockage_type=hdd'));
        $this->assertSame(['B'], $this->noms('stockage_type=hdd&stockage_min=1000'));

        $this->assertSame(['A'], $this->noms('tailles[]=14'));
        $this->assertSame(['B', 'C'], $this->noms('tailles[]=15&tailles[]=11'));
    }

    public function test_filtre_par_famille_categories_et_revente(): void
    {
        $souris = Categorie::where('nom_categorie', 'Souris')->first();
        $word = Categorie::where('nom_categorie', 'Word')->first();

        $this->produit('Laptop revendable');
        $this->produit('Laptop non revendable', ['commission_revente' => null]);
        $this->produit('Souris MX', ['categorie_id' => $souris->id]);
        $this->produit('Licence Word', ['categorie_id' => $word->id]);

        $this->assertSame(['Laptop revendable', 'Licence Word', 'Souris MX'], $this->noms('revente=1'));
        $this->assertSame(['Laptop non revendable', 'Laptop revendable'], $this->noms('famille=ordinateur'));
        $this->assertSame(['Souris MX'], $this->noms('famille=accessoires'));
        $this->assertSame(['Licence Word'], $this->noms('famille=logiciels&categories[]='.$word->id));
        $this->assertSame([], $this->noms('famille=logiciels&categories[]='.$souris->id));
        $this->assertSame(['Licence Word', 'Souris MX'], $this->noms('categories='.$souris->id.','.$word->id));
    }

    public function test_les_produits_non_valides_restent_invisibles(): void
    {
        $this->produit('Visible');
        $this->produit('En attente', ['statut_produit' => STATUT_PRODUIT_EN_ATTENTE]);

        $this->assertSame(['Visible'], $this->noms('revente=1'));
    }

    public function test_apple_et_autre_sont_normalises_en_marque(): void
    {
        $this->assertSame('MACBOOK', $this->produit('MacBook Air M2', ['marque' => 'Apple'])->fresh()->marque);
        $this->assertNull($this->produit('Portable quelconque', ['marque' => 'Autre'])->fresh()->marque);
        $this->assertSame('Acer', $this->produit('Acer Swift', ['marque' => 'Acer'])->fresh()->marque);
    }

    public function test_l_admin_range_une_categorie_dans_les_filtres_et_la_modifie(): void
    {
        $admin = $this->creerAdmin();

        $id = $this->actingAs($admin)->postJson('/api/v1/categories', [
            'nom_categorie' => 'Claviers', 'famille' => 'accessoires', 'libelle' => 'Claviers', 'ordre_filtre' => 9,
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin)->putJson("/api/v1/categories/{$id}", ['groupe' => null, 'libelle' => 'Clavier & pavé', 'ordre_filtre' => 0])
            ->assertOk()->assertJsonPath('data.libelle', 'Clavier & pavé');

        $noms = collect($this->getJson('/api/v1/categories/filtres')->json('data.accessoires.categories'))->pluck('nom')->all();
        $this->assertSame('Clavier & pavé', $noms[0]);

        $this->actingAs($admin)->putJson("/api/v1/categories/{$id}", ['famille' => 'jouets'])->assertUnprocessable();
        // Le nom d'une catégorie reste unique, sauf pour elle-même.
        $this->actingAs($admin)->putJson("/api/v1/categories/{$id}", ['nom_categorie' => 'Souris'])->assertUnprocessable();
        $this->actingAs($admin)->putJson("/api/v1/categories/{$id}", ['nom_categorie' => 'Claviers'])->assertOk();
    }

    public function test_seul_l_admin_modifie_une_categorie(): void
    {
        $categorie = Categorie::where('nom_categorie', 'Souris')->first();

        $this->actingAs($this->creerCoordinateur())->putJson("/api/v1/categories/{$categorie->id}", ['libelle' => 'X'])->assertForbidden();
    }

    public function test_la_publication_accepte_les_champs_de_revente_de_la_boutique(): void
    {
        $produit = $this->produit('HP 250 G8', ['statut_produit' => STATUT_PRODUIT_EN_ATTENTE, 'commission_revente' => null, 'prix' => 100000]);

        $this->actingAs($this->creerCoordinateur())->postJson("/api/v1/produits/{$produit->id}/publier", [
            'prix_vente' => 140000, 'commission_revente' => 9000, 'prix_barre' => 160000, 'pourcentage_reduction' => 13, 'etat_produit' => 'reconditionne',
        ])->assertOk();

        $produit->refresh();
        $this->assertEquals(9000, $produit->commission_revente);
        $this->assertEquals(160000, $produit->prix_barre);
        $this->assertSame(13, $produit->pourcentage_reduction);
        $this->assertSame('reconditionne', $produit->etat_produit);
    }

    public function test_l_admin_fixe_le_prix_de_vente_a_la_creation_mais_pas_le_fournisseur(): void
    {
        $categorie = Categorie::where('nom_categorie', 'Ordinateurs portables')->first();
        $corps = ['categorie_id' => $categorie->id, 'nom_produit' => 'Dell 5420', 'prix' => 200000, 'prix_vente' => 260000, 'quantite_stock' => 3, 'commission_revente' => 8000, 'etat_produit' => 'neuf'];

        $this->actingAs($this->creerAdmin())->postJson('/api/v1/produits', $corps)->assertCreated()->assertJsonPath('data.prix_vente', '260000.00');
        $this->actingAs($this->creerFournisseur())->postJson('/api/v1/produits', $corps)->assertUnprocessable();
    }

    public function test_la_fiche_accepte_la_marque_et_l_etat(): void
    {
        $produit = $this->produit('Portable X');

        $this->actingAs($this->creerCoordinateur())->patchJson("/api/v1/produits/{$produit->id}/fiche", ['marque' => 'Dell', 'etat_produit' => 'occasion'])->assertOk();

        $this->assertSame('DELL', $produit->fresh()->marque);
        $this->assertSame('occasion', $produit->fresh()->etat_produit);
        $this->actingAs($this->creerCoordinateur())->patchJson("/api/v1/produits/{$produit->id}/fiche", ['etat_produit' => 'cassé'])->assertUnprocessable();
    }
}
