<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Fournisseur;
use App\Models\LienAffilie;
use App\Models\Livreur;
use App\Models\Localite;
use App\Models\NotificationOrdispace;
use App\Models\Produit;
use App\Models\User;
use App\Models\VenteBoutique;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Détail produit de l'app Livreur : "Je passe la commande" (le livreur saisit la
 * commande d'un client, qui part à l'Admin) et la fiche du fournisseur du produit.
 */
class BoutiqueCommandeFournisseurTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->creerAgentIa();
    }

    private function creerLivreur(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR, 'prenom' => 'Jean', 'nom' => 'Marc', 'telephone' => '+2250759028545']);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id]);

        return $user;
    }

    private function produitARevendre(array $attributs = []): Produit
    {
        return $this->creerProduitPhysique(array_merge(['quantite_stock' => 5, 'prix' => 100000, 'commission_revente' => 15000], $attributs));
    }

    private function corps(Produit $produit, array $surcharge = []): array
    {
        return array_merge([
            'produit_id' => $produit->id,
            'nom' => 'Yao Brou Kouadio',
            'telephone' => '07 00 11 22 33',
            'localite_id' => Localite::where('nom', 'Cocody')->value('id'),
        ], $surcharge);
    }

    public function test_le_livreur_passe_la_commande_d_un_client_qui_part_a_l_admin(): void
    {
        $livreur = $this->creerLivreur();
        $produit = $this->produitARevendre();

        $reponse = $this->actingAs($livreur)->postJson('/api/v1/boutique/commandes', $this->corps($produit));

        $reponse->assertCreated();
        $this->assertSame(102000, $reponse->json('data.total_a_payer'));

        $commande = Commande::findOrFail($reponse->json('data.reference'));
        $this->assertSame(STATUT_COMMANDE_EN_ATTENTE, $commande->statut_commande);
        $this->assertSame(4, $produit->fresh()->quantite_stock);

        $client = User::findOrFail($commande->client_id);
        $this->assertSame('+2250700112233', $client->telephone);
        $this->assertSame('Yao Brou Kouadio', $client->nom);

        // Vente "commande manuelle" du livreur, commission figée, sans lien.
        $vente = VenteBoutique::where('commande_id', $commande->id)->firstOrFail();
        $this->assertSame($livreur->id, $vente->livreur_id);
        $this->assertSame('manuelle', $vente->source);
        $this->assertNull($vente->lien_affilie_id);
        $this->assertEquals(15000, $vente->commission);

        // Le livreur vient de l'envoyer : pas de notification à lui-même.
        $this->assertFalse(NotificationOrdispace::where('user_id', $livreur->id)->where('type_notification', 'vente_boutique')->exists());

        // L'Admin la voit dans son onglet Commandes, à valider, avec le livreur comme vendeur.
        $ligne = $this->actingAs($this->creerAdmin())->getJson('/api/v1/admin/commandes?statut=en_attente')->json('data.commandes.data.0');
        $this->assertSame($commande->id, $ligne['id']);
        $this->assertSame('boutique', $ligne['origine']);
        $this->assertSame('manuelle', $ligne['source']);
        $this->assertSame('Jean Marc', $ligne['vendeur']);
    }

    public function test_une_commande_refusee_ne_touche_ni_stock_ni_client(): void
    {
        $livreur = $this->creerLivreur();
        $produit = $this->produitARevendre();
        $sansCommission = $this->creerProduitPhysique(['quantite_stock' => 5]);
        $yopougon = Localite::where('nom', 'Yopougon')->firstOrFail();

        // Produit non ouvert à la revente.
        $this->actingAs($livreur)->postJson('/api/v1/boutique/commandes', $this->corps($sansCommission))->assertUnprocessable();
        // Ville non desservie par ce produit.
        $this->actingAs($livreur)->postJson('/api/v1/boutique/commandes', $this->corps($produit, ['localite_id' => $yopougon->id]))->assertUnprocessable();
        // Téléphone invalide, nom manquant.
        $this->actingAs($livreur)->postJson('/api/v1/boutique/commandes', $this->corps($produit, ['telephone' => '123']))->assertUnprocessable();
        $this->actingAs($livreur)->postJson('/api/v1/boutique/commandes', $this->corps($produit, ['nom' => '']))->assertUnprocessable();
        // Numéro d'un compte qui n'est pas un client (ici le livreur lui-même).
        $this->actingAs($livreur)->postJson('/api/v1/boutique/commandes', $this->corps($produit, ['telephone' => $livreur->telephone]))->assertUnprocessable();

        $this->assertSame(5, $produit->fresh()->quantite_stock);
        $this->assertSame(0, Commande::count());
        $this->assertSame(0, User::where('telephone', '+2250700112233')->count());
    }

    public function test_seul_un_livreur_peut_passer_une_commande_par_ce_moyen(): void
    {
        $produit = $this->produitARevendre();

        $this->actingAs($this->creerClient())->postJson('/api/v1/boutique/commandes', $this->corps($produit))->assertForbidden();
    }

    public function test_la_commande_du_livreur_exige_d_etre_connecte(): void
    {
        $this->postJson('/api/v1/boutique/commandes', $this->corps($this->produitARevendre()))->assertUnauthorized();
    }

    public function test_le_livreur_voit_la_fiche_du_fournisseur_sans_donnees_financieres(): void
    {
        $livreur = $this->creerLivreur();
        $produit = $this->produitARevendre();
        $fournisseur = Fournisseur::findOrFail($produit->fournisseur_id);
        $fournisseur->update([
            'nom_entreprise' => 'Victor Informatique', 'nom_gerant' => 'Victor K.', 'telephone_gerant' => '+2250759028545',
            'contact_pro' => '+2250102030405', 'adresse_entreprise' => 'Adjamé, Abidjan', 'horaires_ouverture' => 'Lun-Sam · 9h-19h',
            'zone_couverte' => 'Abidjan', 'lien_maps' => 'https://maps.example/victor', 'taux_commission' => 12, 'solde_portefeuille' => 987654,
        ]);

        $reponse = $this->actingAs($livreur)->getJson("/api/v1/boutique/produits/{$produit->id}/fournisseur");

        $reponse->assertOk();
        $fiche = $reponse->json('data.fournisseur');
        $this->assertSame('Victor Informatique', $fiche['nom_entreprise']);
        $this->assertSame('+2250759028545', $fiche['telephone']);
        $this->assertSame('https://wa.me/2250759028545', $fiche['whatsapp_url']);
        $this->assertSame('+2250102030405', $fiche['contact_pro']);
        $this->assertSame('Adjamé, Abidjan', $fiche['adresse']);
        $this->assertSame('https://maps.example/victor', $fiche['lien_maps']);
        $this->assertStringNotContainsString('taux_commission', json_encode($fiche));
        $this->assertStringNotContainsString('solde', json_encode($fiche));
        $this->assertStringNotContainsString('987654', json_encode($fiche));
    }

    public function test_un_produit_sans_fournisseur_ou_non_publie_n_a_pas_de_fiche(): void
    {
        $livreur = $this->creerLivreur();
        $publieParAdmin = $this->produitARevendre(['fournisseur_id' => null]);
        $nonPublie = $this->produitARevendre(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE]);

        $this->assertNull($this->actingAs($livreur)->getJson("/api/v1/boutique/produits/{$publieParAdmin->id}/fournisseur")->assertOk()->json('data.fournisseur'));
        $this->actingAs($livreur)->getJson("/api/v1/boutique/produits/{$nonPublie->id}/fournisseur")->assertNotFound();
        $this->actingAs($this->creerClient())->getJson("/api/v1/boutique/produits/{$publieParAdmin->id}/fournisseur")->assertForbidden();
    }

    public function test_le_profil_boutique_liste_les_fournisseurs_des_produits_du_livreur(): void
    {
        $livreur = $this->creerLivreur();
        $joignable = $this->produitARevendre();
        Fournisseur::where('user_id', $joignable->fournisseur_id)->update(['nom_entreprise' => 'Joignable SARL', 'telephone_gerant' => '+2250700000001']);
        $injoignable = $this->produitARevendre(); // fournisseur sans téléphone, adresse ni lien : écarté
        User::whereKey($injoignable->fournisseur_id)->update(['telephone' => null]);
        $autre = $this->produitARevendre(); // produit que ce livreur ne revend pas
        Fournisseur::where('user_id', $autre->fournisseur_id)->update(['nom_entreprise' => 'Autre SARL', 'telephone_gerant' => '+2250700000002']);

        foreach ([$joignable, $injoignable] as $produit) {
            LienAffilie::create(['produit_id' => $produit->id, 'livreur_id' => $livreur->id, 'code' => 'c'.$produit->id.'xxxxxxx']);
        }

        $fournisseurs = $this->actingAs($livreur)->getJson('/api/v1/boutique/fournisseurs')->assertOk()->json('data');

        $this->assertSame(['Joignable SARL'], array_column($fournisseurs, 'nom_entreprise'));
        $this->actingAs($this->creerClient())->getJson('/api/v1/boutique/fournisseurs')->assertForbidden();
    }
}
