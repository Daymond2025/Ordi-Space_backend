<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Commande;
use App\Models\FraisLivraisonProduit;
use App\Models\LienAffilie;
use App\Models\Livreur;
use App\Models\Localite;
use App\Models\NotificationOrdispace;
use App\Models\Produit;
use App\Models\User;
use App\Models\VenteBoutique;
use App\Models\Vitrine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\Feature\Concerns\PasseCommandePublique;
use App\Models\AcompteConfirmation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Page d'arrivée de l'acheteur (dossier page_commande) : consultation d'un
 * lien de vente ou d'une vitrine, compteurs de visite, et commande SANS compte
 * rattachée au vendeur — créée une fois la confirmation payée sur Wave.
 */
class BoutiquePubliqueTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi, PasseCommandePublique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->creerAgentIa();
        $this->configurerWavePublic();
        $this->fauxWave();
    }

    private function creerLivreur(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR, 'prenom' => 'Jean', 'nom' => 'Marc', 'telephone' => '+2250759028545']);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id]);

        return $user;
    }

    /** Produit à revendre (commission renseignée), livrable à Cocody (creerProduitPhysique : 2 000 F). */
    private function produitARevendre(array $attributs = []): Produit
    {
        return $this->creerProduitPhysique(array_merge(['quantite_stock' => 5, 'prix' => 100000, 'commission_revente' => 15000], $attributs));
    }

    private function corpsCommande(array $surcharge = []): array
    {
        return array_merge([
            'quantite' => 1,
            'nom' => 'Kouassi',
            'prenom' => 'Awa',
            'telephone' => '07 11 22 33 44',
            'localite_id' => Localite::where('nom', 'Cocody')->value('id'),
            'adresse' => 'Riviera Palmeraie, près de la pharmacie',
        ], $surcharge);
    }

    private function lienPour(User $livreur, Produit $produit): LienAffilie
    {
        return LienAffilie::create(['produit_id' => $produit->id, 'livreur_id' => $livreur->id, 'code' => 'abc12345']);
    }

    public function test_la_fiche_d_un_lien_expose_le_produit_et_le_conseiller_sans_la_commission(): void
    {
        $livreur = $this->creerLivreur();
        $produit = $this->produitARevendre(['nom_produit' => 'Dell Latitude 5420']);
        $lien = $this->lienPour($livreur, $produit);

        $reponse = $this->getJson("/api/v1/public/liens/{$lien->code}");

        $reponse->assertOk();
        $this->assertSame($produit->id, $reponse->json('data.produit.id'));
        $this->assertSame('DELL', $reponse->json('data.produit.marque'));
        $this->assertSame('Jean M.', $reponse->json('data.vendeur.nom'));
        $this->assertSame('https://wa.me/2250759028545', $reponse->json('data.vendeur.whatsapp_url'));
        $this->assertNotEmpty($reponse->json('data.produit.frais_livraison'));
        $this->assertStringNotContainsString('commission', json_encode($reponse->json('data')));
    }

    public function test_un_lien_inconnu_ou_vers_un_produit_non_publie_est_introuvable(): void
    {
        $livreur = $this->creerLivreur();
        $produit = $this->produitARevendre(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE]);
        $lien = $this->lienPour($livreur, $produit);

        $this->getJson('/api/v1/public/liens/zzzzzzzz')->assertNotFound();
        $this->getJson("/api/v1/public/liens/{$lien->code}")->assertNotFound();
    }

    public function test_la_vitrine_liste_la_selection_du_vendeur_et_seulement_elle(): void
    {
        $livreur = $this->creerLivreur();
        $vitrine = Vitrine::pour($livreur);
        $ouvert = $this->produitARevendre(['nom_produit' => 'Laptop A revendre']);
        $this->creerProduitPhysique(['nom_produit' => 'Sans commission', 'quantite_stock' => 5]);
        $this->produitARevendre(['nom_produit' => 'En rupture', 'quantite_stock' => 0]);

        $reponse = $this->getJson("/api/v1/public/vitrines/{$vitrine->code}");

        $reponse->assertOk();
        $noms = array_column($reponse->json('data.produits'), 'nom_produit');
        $this->assertSame(['Laptop A revendre'], $noms);
        $this->assertSame($ouvert->id, $reponse->json('data.produits.0.id'));

        $this->getJson("/api/v1/public/vitrines/{$vitrine->code}/produits/{$ouvert->id}")->assertOk();
        $horsSelection = Produit::where('nom_produit', 'Sans commission')->firstOrFail();
        $this->getJson("/api/v1/public/vitrines/{$vitrine->code}/produits/{$horsSelection->id}")->assertNotFound();
    }

    public function test_les_visites_sont_comptees_a_part_pour_le_lien_les_clics_et_les_scans(): void
    {
        $livreur = $this->creerLivreur();
        $lien = $this->lienPour($livreur, $this->produitARevendre());
        $vitrine = Vitrine::pour($livreur);

        // Consulter la fiche ne compte rien : seul l'appel explicite du navigateur compte.
        $this->getJson("/api/v1/public/liens/{$lien->code}")->assertOk();
        $this->assertSame(0, $lien->fresh()->vues);

        $this->postJson("/api/v1/public/liens/{$lien->code}/vue")->assertOk();
        $this->postJson("/api/v1/public/liens/{$lien->code}/vue")->assertOk();
        $this->assertSame(2, $lien->fresh()->vues);
        $this->assertNotNull($lien->fresh()->derniere_activite_le);

        $this->postJson("/api/v1/public/vitrines/{$vitrine->code}/vue")->assertOk();
        $this->postJson("/api/v1/public/vitrines/{$vitrine->code}/vue?src=qr")->assertOk();
        $this->postJson("/api/v1/public/vitrines/{$vitrine->code}/vue?src=qr")->assertOk();
        $this->assertSame(1, $vitrine->fresh()->clics);
        $this->assertSame(2, $vitrine->fresh()->scans);
    }

    public function test_commander_depuis_un_lien_cree_client_commande_et_vente_rattachee(): void
    {
        $livreur = $this->creerLivreur();
        $produit = $this->produitARevendre();
        $lien = $this->lienPour($livreur, $produit);

        $init = $this->ouvrirConfirmation($this->corpsCommande(['origine' => 'lien', 'code' => $lien->code, 'quantite' => 2]));

        $init->assertCreated();
        $this->assertSame(200, $init->json('data.montant_confirmation'));
        $this->assertSame(STATUT_PAIEMENT_EN_ATTENTE, $init->json('data.statut'));
        $this->assertSame('https://pay.wave.com/c/cos_1', $init->json('data.wave_launch_url'));
        $this->assertSame(200000, $init->json('data.montant_produits'));
        $this->assertSame(2000, $init->json('data.frais_livraison'));
        $this->assertSame(202000, $init->json('data.total_a_payer'));
        $this->assertSame(201800, $init->json('data.reliquat'));

        // Tant que Wave n'a pas confirmé : ni commande, ni stock retenu, ni client, ni notification.
        $this->assertSame(0, Commande::count());
        $this->assertSame(5, $produit->fresh()->quantite_stock);
        $this->assertSame(0, User::where('telephone', '+2250711223344')->count());
        $this->assertFalse(NotificationOrdispace::where('user_id', $livreur->id)->where('type_notification', 'vente_boutique')->exists());

        $this->webhookWave('cos_1')->assertOk();

        $commande = Commande::firstOrFail();
        $this->assertSame(STATUT_COMMANDE_EN_ATTENTE, $commande->statut_commande);
        $this->assertSame(3, $produit->fresh()->quantite_stock);
        $this->assertSame(2, $commande->lignes->first()->quantite);
        $this->assertNotNull($commande->livraison);
        $this->assertSame('Riviera Palmeraie, près de la pharmacie', $commande->livraison->adresse->rue);
        // La confirmation est déduite : le client ne doit plus que son reliquat, que le livreur encaisse.
        $this->assertEquals(202000, $commande->montantNet());
        $this->assertEquals(200, $commande->acomptePaye());
        $this->assertEquals(201800, $commande->reliquat());

        $client = User::findOrFail($commande->client_id);
        $this->assertSame('+2250711223344', $client->telephone);
        $this->assertSame('Awa', $client->prenom);

        // Rattachée au vendeur, commission figée (2 × 15 000), source "lien".
        $vente = VenteBoutique::where('commande_id', $commande->id)->firstOrFail();
        $this->assertSame($livreur->id, $vente->livreur_id);
        $this->assertSame($lien->id, $vente->lien_affilie_id);
        $this->assertSame('whatsapp', $vente->source);
        $this->assertEquals(30000, $vente->commission);

        $this->assertTrue(NotificationOrdispace::where('user_id', $livreur->id)->where('type_notification', 'vente_boutique')->exists());
        $this->assertDatabaseHas('messages', ['commande_id' => $commande->id, 'type' => TYPE_MESSAGE_COMMANDE_CREEE]);
        $this->assertDatabaseHas('journal_audit', ['commande_id' => $commande->id, 'action' => ACTION_ACOMPTE_CONFIRMATION_PAYE]);
    }

    public function test_commander_depuis_la_vitrine_par_qr_compte_comme_une_vente_qr(): void
    {
        $livreur = $this->creerLivreur();
        $vitrine = Vitrine::pour($livreur);
        $produit = $this->produitARevendre();

        $commande = $this->passerCommandePublique($this->corpsCommande([
            'origine' => 'vitrine', 'code' => $vitrine->code, 'produit_id' => $produit->id, 'src' => 'qr',
        ]));

        $vente = VenteBoutique::where('commande_id', $commande->id)->firstOrFail();
        $this->assertSame('qr', $vente->source);
        $this->assertNull($vente->lien_affilie_id);
        $this->assertSame($livreur->id, $vente->livreur_id);
    }

    public function test_un_acheteur_deja_connu_est_reutilise_par_son_numero(): void
    {
        $livreur = $this->creerLivreur();
        $lien = $this->lienPour($livreur, $this->produitARevendre());
        $corps = $this->corpsCommande(['origine' => 'lien', 'code' => $lien->code]);

        $this->passerCommandePublique($corps);
        $this->passerCommandePublique($corps);

        $this->assertSame(1, User::where('telephone', '+2250711223344')->count());
        $this->assertSame(2, Commande::where('client_id', User::where('telephone', '+2250711223344')->value('id'))->count());
        $this->assertSame(1, Client::where('user_id', User::where('telephone', '+2250711223344')->value('id'))->count());
    }

    public function test_les_commandes_refusees_ne_font_rien_payer_ni_toucher_au_stock(): void
    {
        $livreur = $this->creerLivreur();
        $produit = $this->produitARevendre(['quantite_stock' => 1]);
        $lien = $this->lienPour($livreur, $produit);
        $base = ['origine' => 'lien', 'code' => $lien->code];

        // Stock insuffisant.
        $this->ouvrirConfirmation($this->corpsCommande($base + ['quantite' => 2]))->assertUnprocessable();

        // Localité non desservie (aucun tarif pour ce produit).
        $yopougon = Localite::where('nom', 'Yopougon')->firstOrFail();
        $this->assertFalse(FraisLivraisonProduit::where('produit_id', $produit->id)->where('localite_id', $yopougon->id)->exists());
        $this->ouvrirConfirmation($this->corpsCommande($base + ['localite_id' => $yopougon->id]))->assertUnprocessable();

        // Champs manquants.
        $this->ouvrirConfirmation($base)->assertUnprocessable();

        // Aucune session Wave ouverte : l'acheteur n'a rien à payer pour une commande impossible.
        Http::assertNothingSent();
        $this->assertSame(0, AcompteConfirmation::count());
        $this->assertSame(1, $produit->fresh()->quantite_stock);
        $this->assertSame(0, Commande::count());
    }

    public function test_le_numero_d_un_compte_non_client_est_refuse_a_la_creation_de_la_commande(): void
    {
        $livreur = $this->creerLivreur();
        $lien = $this->lienPour($livreur, $this->produitARevendre());

        // Le contrôle du numéro se fait à la création de la commande (après paiement) : l'acheteur
        // a payé, la commande est impossible — anomalie que l'Admin traite.
        $this->ouvrirConfirmation($this->corpsCommande(['origine' => 'lien', 'code' => $lien->code, 'telephone' => $livreur->telephone]))->assertCreated();
        $this->webhookWave('cos_1')->assertOk();

        $acompte = AcompteConfirmation::firstOrFail();
        $this->assertTrue($acompte->estAnomalie());
        $this->assertStringContainsString('numéro', $acompte->erreur);
        $this->assertSame(0, Commande::count());
    }

    public function test_un_produit_hors_selection_ne_se_commande_pas_par_vitrine(): void
    {
        $livreur = $this->creerLivreur();
        $vitrine = Vitrine::pour($livreur);
        $sansCommission = $this->creerProduitPhysique(['quantite_stock' => 5]);

        $this->ouvrirConfirmation($this->corpsCommande([
            'origine' => 'vitrine', 'code' => $vitrine->code, 'produit_id' => $sansCommission->id,
        ]))->assertUnprocessable();
    }

    public function test_les_liens_publics_pointent_vers_la_page_commande(): void
    {
        config(['services.page_commande.url' => 'https://commande.exemple.test/']);
        $livreur = $this->creerLivreur();
        $lien = $this->lienPour($livreur, $this->produitARevendre());
        $vitrine = Vitrine::pour($livreur);

        $this->assertSame("https://commande.exemple.test/boutique/produit/{$lien->code}", $lien->url());
        $this->assertSame("https://commande.exemple.test/boutique/vitrine/{$vitrine->code}", $vitrine->url());
        $this->assertSame($vitrine->url().'?src=qr', $vitrine->urlQr());
    }
}
