<?php

namespace Tests\Feature;

use App\Models\DemandeSav;
use App\Models\Localite;
use App\Models\Partenaire;
use App\Models\Produit;
use App\Models\StockPartenaire;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Partenaire Ordi'Space : Ordi'Space lui cède un produit (ex. 150 000 F),
 * il le revend au prix qu'il veut (ex. 200 000 F) et garde la différence.
 * Approvisionnement payé d'avance, stock tracé, clients de ses villes,
 * SAV d'abord chez lui puis transférable à Ordi'Space.
 */
class PartenaireTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    private Localite $yamoussoukro;

    private Localite $bouake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake(PARTENAIRE_PREUVE_DISQUE);
        $this->yamoussoukro = Localite::where('nom', 'Yamoussoukro')->firstOrFail();
        $this->bouake = Localite::where('nom', 'Bouaké')->firstOrFail();
    }

    private function creerPartenaire(array $localites = [], float $remise = 0, string $telephone = '0701020304'): User
    {
        $reponse = $this->actingAs($this->creerAdmin())->postJson('/api/v1/admin/utilisateurs', [
            'type_utilisateur' => ROLE_PARTENAIRE,
            'nom' => 'Kouassi',
            'telephone' => $telephone,
            'nom_boutique' => 'Ordi Yakro '.$telephone,
            'remise_defaut' => $remise,
            'localite_ids' => array_map(fn (Localite $l) => $l->id, $localites ?: [$this->yamoussoukro]),
        ])->assertCreated();

        return User::findOrFail($reponse->json('data.id'));
    }

    /** Ordinateur cédé 150 000 F (prix Ordi'Space, sans remise), coût fournisseur 120 000 F. */
    private function ordinateur(array $attributs = []): Produit
    {
        return $this->creerProduitPhysique(['prix' => 120000, 'prix_vente' => 150000, 'quantite_stock' => 10, ...$attributs]);
    }

    /** Approvisionnement complet jusqu'à la réception (le stock entre). */
    private function approvisionner(User $partenaire, Produit $produit, int $quantite): void
    {
        $id = $this->actingAs($partenaire)->postJson('/api/v1/partenaire/approvisionnements', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => $quantite]],
        ])->assertCreated()->json('data.id');

        $this->actingAs($partenaire)->post("/api/v1/partenaire/approvisionnements/{$id}/paiement", [
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY,
            'reference_paiement' => 'OM-123456',
            'preuve' => UploadedFile::fake()->create('recu.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $admin = $this->creerAdmin();
        $this->actingAs($admin)->postJson("/api/v1/admin/approvisionnements-partenaires/{$id}/valider")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/admin/approvisionnements-partenaires/{$id}/expedier")->assertOk();
        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/approvisionnements/{$id}/reception")->assertOk();
    }

    private function commander(User $client, User $partenaire, Produit $produit, int $quantite = 1, array $extra = []): array
    {
        return $this->actingAs($client)->postJson('/api/v1/commandes-partenaire', [
            'partenaire_id' => $partenaire->id,
            'localite_id' => $this->yamoussoukro->id,
            'mode_remise' => MODE_REMISE_LIVRAISON,
            'adresse_livraison' => 'Quartier Habitat, Yamoussoukro',
            'telephone_contact' => '0505050505',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => $quantite]],
            ...$extra,
        ])->assertCreated()->json('data');
    }

    private function statut(User $partenaire, array $commande, string $statut, ?string $motif = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($partenaire)->postJson("/api/v1/partenaire/commandes/{$commande['id']}/statut", array_filter([
            'statut_commande' => $statut, 'motif' => $motif,
        ]));
    }

    public function test_l_admin_cree_un_partenaire_qui_se_connecte_par_telephone(): void
    {
        $partenaire = $this->creerPartenaire();

        $this->assertSame('+2250701020304', $partenaire->telephone);
        $this->assertTrue($partenaire->hasRole(ROLE_PARTENAIRE));
        $this->assertTrue(Partenaire::find($partenaire->id)->localites->contains($this->yamoussoukro));

        $this->postJson('/api/v1/auth/telephone/otp', ['telephone' => '07 01 02 03 04', 'type_utilisateur' => ROLE_PARTENAIRE])
            ->assertOk()->assertJsonPath('data.compte_existant', true);

        // Deux partenaires sur le même numéro rendraient la connexion ambiguë.
        $this->actingAs($this->creerAdmin())->postJson('/api/v1/admin/utilisateurs', [
            'type_utilisateur' => ROLE_PARTENAIRE, 'nom' => 'Doublon', 'telephone' => '+225 07 01 02 03 04',
            'nom_boutique' => 'X', 'localite_ids' => [$this->yamoussoukro->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('telephone', 'error.fields');
    }

    public function test_prix_de_cession_remise_par_defaut_et_exception_sans_fuite_du_prix_fournisseur(): void
    {
        $partenaire = $this->creerPartenaire(remise: 10);
        $produit = $this->ordinateur();
        $autre = $this->ordinateur(['nom_produit' => 'Laptop Pro']);

        $this->actingAs($this->creerAdmin())->putJson("/api/v1/admin/partenaires/{$partenaire->id}/tarifs/{$autre->id}", ['prix_cession' => 140000])->assertOk();

        $catalogue = $this->actingAs($partenaire)->getJson('/api/v1/partenaire/catalogue')->assertOk()->json('data.data');
        $parId = collect($catalogue)->keyBy('id');

        $this->assertEquals(135000, $parId[$produit->id]['prix_cession']); // 150 000 − 10 %
        $this->assertEquals(140000, $parId[$autre->id]['prix_cession']);   // exception
        $this->assertArrayNotHasKey('prix', $parId[$produit->id]);
        $this->assertArrayNotHasKey('commission_ordispace', $parId[$produit->id]);
        $this->assertStringNotContainsString('120000', json_encode($catalogue));
    }

    public function test_le_partenaire_ne_peut_pas_revendre_sous_son_prix_de_cession(): void
    {
        $partenaire = $this->creerPartenaire();
        $produit = $this->ordinateur();

        $this->actingAs($partenaire)->putJson("/api/v1/partenaire/catalogue/{$produit->id}/offre", ['prix_revente' => 149000])
            ->assertUnprocessable()->assertJsonValidationErrors('prix_revente', 'error.fields');

        $this->actingAs($partenaire)->putJson("/api/v1/partenaire/catalogue/{$produit->id}/offre", ['prix_revente' => 200000])
            ->assertOk()->assertJsonPath('data.actif', true);
    }

    public function test_approvisionnement_paye_d_avance_le_stock_n_entre_qu_a_la_reception(): void
    {
        $partenaire = $this->creerPartenaire();
        $produit = $this->ordinateur();
        $admin = $this->creerAdmin();

        $appro = $this->actingAs($partenaire)->postJson('/api/v1/partenaire/approvisionnements', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 3]],
        ])->assertCreated()->assertJsonPath('data.statut', STATUT_APPRO_EN_ATTENTE_PAIEMENT)->json('data');
        $this->assertEquals(450000, $appro['montant_total']);

        // Pas d'expédition sans paiement validé.
        $this->actingAs($admin)->postJson("/api/v1/admin/approvisionnements-partenaires/{$appro['id']}/expedier")->assertUnprocessable();

        $this->actingAs($partenaire)->post("/api/v1/partenaire/approvisionnements/{$appro['id']}/paiement", [
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY, 'reference_paiement' => 'OM-1', 'preuve' => UploadedFile::fake()->create('recu.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.a_preuve_paiement', true)->assertJsonMissingPath('data.preuve_paiement');

        // Refus puis nouvelle déclaration.
        $this->actingAs($admin)->postJson("/api/v1/admin/approvisionnements-partenaires/{$appro['id']}/refuser", ['motif' => 'Montant non reçu'])
            ->assertOk()->assertJsonPath('data.statut', STATUT_APPRO_EN_ATTENTE_PAIEMENT);
        $this->actingAs($partenaire)->post("/api/v1/partenaire/approvisionnements/{$appro['id']}/paiement", [
            'mode_paiement' => MODE_PAIEMENT_MOBILE_MONEY, 'reference_paiement' => 'OM-2', 'preuve' => UploadedFile::fake()->create('recu2.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($admin)->get("/api/v1/admin/approvisionnements-partenaires/{$appro['id']}/preuve")->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/admin/approvisionnements-partenaires/{$appro['id']}/valider")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/admin/approvisionnements-partenaires/{$appro['id']}/expedier")->assertOk();

        $this->assertSame(7, $produit->fresh()->quantite_stock); // stock Ordi'Space
        $this->assertSame(0, Partenaire::find($partenaire->id)->quantiteEnStock($produit->id));

        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/approvisionnements/{$appro['id']}/reception")->assertOk();
        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/approvisionnements/{$appro['id']}/reception")->assertUnprocessable();

        $this->assertSame(3, Partenaire::find($partenaire->id)->quantiteEnStock($produit->id));
    }

    public function test_le_client_choisit_parmi_les_partenaires_de_sa_ville(): void
    {
        $produit = $this->ordinateur();
        $yakro1 = $this->creerPartenaire(telephone: '0701000001');
        $yakro2 = $this->creerPartenaire(telephone: '0701000002');
        $bouake = $this->creerPartenaire([$this->bouake], telephone: '0701000003');
        $suspendu = $this->creerPartenaire(telephone: '0701000004');

        foreach ([[$yakro1, 210000], [$yakro2, 190000], [$bouake, 180000], [$suspendu, 170000]] as [$p, $prix]) {
            $this->actingAs($p)->putJson("/api/v1/partenaire/catalogue/{$produit->id}/offre", ['prix_revente' => $prix])->assertOk();
        }
        $suspendu->update(['statut_compte' => STATUT_COMPTE_SUSPENDU]);
        $this->approvisionner($yakro1, $produit, 1);

        $liste = $this->actingAs($this->creerClient())->getJson("/api/v1/produits/{$produit->id}/partenaires?localite_id={$this->yamoussoukro->id}")
            ->assertOk()->json('data.partenaires');

        $this->assertSame([$yakro1->id, $yakro2->id], array_column($liste, 'partenaire_id')); // en stock d'abord
        $this->assertSame([true, false], array_column($liste, 'en_stock'));
        $this->assertStringNotContainsString('prix_cession', json_encode($liste));
    }

    public function test_vente_complete_le_benefice_revient_au_partenaire(): void
    {
        $partenaire = $this->creerPartenaire();
        $produit = $this->ordinateur();
        $client = $this->creerClient();
        $this->actingAs($partenaire)->putJson("/api/v1/partenaire/catalogue/{$produit->id}/offre", ['prix_revente' => 200000])->assertOk();

        $commande = $this->commander($client, $partenaire, $produit, extra: ['lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire' => 1]]]);
        $this->assertEquals(200000, $commande['montant_total']); // prix relu côté serveur
        $this->assertStringNotContainsString('prix_cession', json_encode($commande));

        // Pas de stock : impossible de valider, il faut s'approvisionner.
        $this->statut($partenaire, $commande, STATUT_COMMANDE_VALIDEE)->assertUnprocessable()->assertJsonValidationErrors('statut_commande', 'error.fields');
        // Les statuts "en attente" ne touchent pas au stock.
        $this->statut($partenaire, $commande, STATUT_COMMANDE_REPORTEE)->assertOk()->assertJsonPath('data.statut_commande', STATUT_COMMANDE_REPORTEE);

        $this->approvisionner($partenaire, $produit, 2);

        $this->statut($partenaire, $commande, STATUT_COMMANDE_VALIDEE)->assertOk()->assertJsonPath('data.statut_commande', STATUT_COMMANDE_VALIDEE);
        $this->assertSame(1, Partenaire::find($partenaire->id)->quantiteEnStock($produit->id));

        $this->statut($partenaire, $commande, STATUT_COMMANDE_EN_LIVRAISON)->assertOk();
        $this->assertSame(1, Partenaire::find($partenaire->id)->quantiteEnStock($produit->id)); // pas de double sortie
        $this->statut($partenaire, $commande, STATUT_COMMANDE_LIVREE)
            ->assertOk()->assertJsonPath('data.statut_commande', STATUT_COMMANDE_LIVREE)->assertJsonPath('data.benefice', 50000);
        $this->statut($partenaire, $commande, STATUT_COMMANDE_EN_ATTENTE)->assertUnprocessable(); // livrée = définitive

        $this->actingAs($partenaire)->getJson('/api/v1/partenaire/moi')->assertOk()
            ->assertJsonPath('data.statistiques.chiffre_affaires', 200000)
            ->assertJsonPath('data.statistiques.benefice', 50000);

        $this->actingAs($this->creerAdmin())->getJson("/api/v1/admin/partenaires/{$partenaire->id}")->assertOk()
            ->assertJsonPath('data.statistiques.montant_ordispace', 150000)
            ->assertJsonPath('data.statistiques.benefice_partenaire', 50000);
    }

    public function test_annuler_une_commande_acceptee_remet_le_stock(): void
    {
        $partenaire = $this->creerPartenaire();
        $produit = $this->ordinateur();
        $this->actingAs($partenaire)->putJson("/api/v1/partenaire/catalogue/{$produit->id}/offre", ['prix_revente' => 200000]);
        $this->approvisionner($partenaire, $produit, 1);
        $client = $this->creerClient();
        $commande = $this->commander($client, $partenaire, $produit);

        $this->statut($partenaire, $commande, STATUT_COMMANDE_VALIDEE)->assertOk();
        $this->assertSame(0, Partenaire::find($partenaire->id)->quantiteEnStock($produit->id));

        // Le client ne peut plus annuler seul une commande validée.
        $this->actingAs($client)->postJson("/api/v1/commandes-partenaire/{$commande['id']}/annuler")->assertUnprocessable();

        $this->statut($partenaire, $commande, STATUT_COMMANDE_ANNULEE)->assertUnprocessable(); // motif obligatoire
        $this->statut($partenaire, $commande, STATUT_COMMANDE_ANNULEE, 'Client injoignable')
            ->assertOk()->assertJsonPath('data.statut_commande', STATUT_COMMANDE_ANNULEE);
        $this->assertSame(1, Partenaire::find($partenaire->id)->quantiteEnStock($produit->id));
    }

    public function test_stock_jamais_negatif_et_le_partenaire_ne_s_ajoute_pas_de_stock(): void
    {
        $partenaire = $this->creerPartenaire();
        $produit = $this->ordinateur();
        $this->approvisionner($partenaire, $produit, 2);

        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/stock/{$produit->id}/sortie", ['quantite' => 5, 'motif' => 'Casse'])
            ->assertUnprocessable()->assertJsonValidationErrors('stock', 'error.fields');
        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/stock/{$produit->id}/sortie", ['quantite' => -3, 'motif' => 'Ajout'])
            ->assertUnprocessable();
        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/stock/{$produit->id}/sortie", ['quantite' => 1, 'motif' => 'Écran cassé'])->assertOk();

        $this->assertSame(1, StockPartenaire::where('partenaire_id', $partenaire->id)->value('quantite'));
        $this->assertSame(2, $this->actingAs($partenaire)->getJson('/api/v1/partenaire/stock/mouvements')->json('data.total'));
    }

    public function test_cloisonnement_entre_partenaires_clients_et_admin(): void
    {
        $a = $this->creerPartenaire(telephone: '0701000001');
        $b = $this->creerPartenaire(telephone: '0701000002');
        $produit = $this->ordinateur();
        $this->actingAs($a)->putJson("/api/v1/partenaire/catalogue/{$produit->id}/offre", ['prix_revente' => 200000]);
        $client = $this->creerClient();
        $commande = $this->commander($client, $a, $produit);
        $appro = $this->actingAs($a)->postJson('/api/v1/partenaire/approvisionnements', ['lignes' => [['produit_id' => $produit->id, 'quantite' => 1]]])->json('data');

        $this->actingAs($b)->getJson("/api/v1/partenaire/commandes/{$commande['id']}")->assertNotFound();
        $this->statut($b, $commande, STATUT_COMMANDE_VALIDEE)->assertNotFound();
        $this->actingAs($b)->getJson("/api/v1/partenaire/commandes/{$commande['id']}/messages")->assertNotFound();
        $this->actingAs($b)->getJson("/api/v1/partenaire/approvisionnements/{$appro['id']}")->assertNotFound();
        $this->assertSame(0, $this->actingAs($b)->getJson('/api/v1/partenaire/commandes')->json('data.commandes.total'));

        $this->actingAs($this->creerClient())->getJson("/api/v1/commandes-partenaire/{$commande['id']}")->assertNotFound();
        $this->actingAs($client)->getJson('/api/v1/partenaire/catalogue')->assertForbidden();
        $this->actingAs($a)->getJson('/api/v1/admin/partenaires')->assertForbidden();
        $this->actingAs($a)->postJson("/api/v1/admin/approvisionnements-partenaires/{$appro['id']}/valider")->assertForbidden();
        $this->actingAs($a)->getJson('/api/v1/commandes')->assertForbidden();

        // Un partenaire qui ne dessert pas la ville ne reçoit pas la commande.
        $this->actingAs($client)->postJson('/api/v1/commandes-partenaire', [
            'partenaire_id' => $a->id, 'localite_id' => $this->bouake->id, 'mode_remise' => MODE_REMISE_RETRAIT_BOUTIQUE,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('partenaire_id', 'error.fields');

        // Admin restreint sans l'espace "partenaires".
        $this->actingAs($this->creerAdminRestreint(['clients']))->getJson('/api/v1/admin/partenaires')->assertForbidden();
    }

    public function test_sav_d_abord_chez_le_partenaire_puis_transfere_a_ordispace(): void
    {
        $partenaire = $this->creerPartenaire();
        $produit = $this->ordinateur();
        $this->actingAs($partenaire)->putJson("/api/v1/partenaire/catalogue/{$produit->id}/offre", ['prix_revente' => 200000]);
        $this->approvisionner($partenaire, $produit, 1);
        $client = $this->creerClient();
        $commande = $this->commander($client, $partenaire, $produit, extra: ['mode_remise' => MODE_REMISE_RETRAIT_BOUTIQUE]);
        $ligneId = $commande['lignes'][0]['id'];

        // Pas de SAV avant la remise du produit.
        $this->actingAs($client)->postJson('/api/v1/sav/demandes', ['ligne_commande_partenaire_id' => $ligneId, 'description_probleme' => 'Écran noir'])
            ->assertUnprocessable();

        $this->statut($partenaire, $commande, STATUT_COMMANDE_LIVREE)->assertOk(); // retrait boutique : livrée directement

        $this->actingAs($this->creerClient())->postJson('/api/v1/sav/demandes', ['ligne_commande_partenaire_id' => $ligneId, 'description_probleme' => 'Intrus'])
            ->assertForbidden();

        $demandeId = $this->actingAs($client)->postJson('/api/v1/sav/demandes', ['ligne_commande_partenaire_id' => $ligneId, 'description_probleme' => 'Écran noir'])
            ->assertCreated()->json('data.id');

        $technicien = $this->creerTechnicien();
        $coordinateur = $this->creerCoordinateur();
        $this->assertSame(0, $this->actingAs($technicien)->getJson('/api/v1/sav/demandes')->json('data.total'));
        $this->assertSame(0, $this->actingAs($coordinateur)->getJson('/api/v1/sav/demandes')->json('data.total'));
        $this->actingAs($technicien)->getJson("/api/v1/sav/demandes/{$demandeId}")->assertForbidden();
        $this->assertSame(1, $this->actingAs($partenaire)->getJson('/api/v1/partenaire/sav')->json('data.total'));

        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/sav/{$demandeId}/transferer", ['motif' => 'Carte mère à remplacer'])->assertOk();

        $this->assertSame(1, $this->actingAs($coordinateur)->getJson('/api/v1/sav/demandes')->json('data.total'));
        $this->actingAs($technicien)->getJson("/api/v1/sav/demandes/{$demandeId}")->assertOk();
        $this->actingAs($partenaire)->patchJson("/api/v1/partenaire/sav/{$demandeId}/statut", ['statut_demande' => STATUT_DEMANDE_SAV_RESOLUE])
            ->assertUnprocessable();
        $this->assertNotNull(DemandeSav::find($demandeId)->transferee_le);
    }

    public function test_accueil_compteurs_et_produits_commandes(): void
    {
        $partenaire = $this->creerPartenaire();
        $produit = $this->ordinateur();
        $this->actingAs($partenaire)->putJson("/api/v1/partenaire/catalogue/{$produit->id}/offre", ['prix_revente' => 200000]);
        $this->approvisionner($partenaire, $produit, 3);
        $client = $this->creerClient();

        $livree = $this->commander($client, $partenaire, $produit, extra: ['mode_remise' => MODE_REMISE_RETRAIT_BOUTIQUE]);
        $this->statut($partenaire, $livree, STATUT_COMMANDE_LIVREE)->assertOk();
        $this->commander($client, $partenaire, $produit); // nouvelle, à accepter

        $accueil = $this->actingAs($partenaire)->getJson('/api/v1/partenaire/accueil')->assertOk()->json('data');

        $this->assertSame('tout', $accueil['periode']);
        $this->assertEquals([
            'produits_en_stock' => 1, 'produits_vendus' => 1, 'nouvelles_commandes' => 1,
            'chiffre_affaires' => 200000, 'investissement' => 450000, 'benefice' => 50000,
        ], $accueil['statistiques']);
        $this->assertCount(1, $accueil['produits']);
        $this->assertSame(['commandes' => 2, 'livrees' => 1, 'en_cours' => 1, 'annulees' => 0, 'nouvelles' => 1],
            array_intersect_key($accueil['produits'][0], array_flip(['commandes', 'livrees', 'en_cours', 'annulees', 'nouvelles'])));

        $this->actingAs($partenaire)->getJson('/api/v1/partenaire/accueil?periode=semaine')->assertOk()->assertJsonPath('data.statistiques.produits_vendus', 1);
    }

    public function test_suivi_et_conversations_visibles_du_partenaire_et_du_coordinateur_seulement(): void
    {
        $partenaire = $this->creerPartenaire();
        $produit = $this->ordinateur();
        $this->actingAs($partenaire)->putJson("/api/v1/partenaire/catalogue/{$produit->id}/offre", ['prix_revente' => 200000]);
        $this->approvisionner($partenaire, $produit, 1);
        $commande = $this->commander($this->creerClient(), $partenaire, $produit);
        $coordinateur = $this->creerCoordinateur();

        // Fil produit : la carte "Nouvelle commande" (prix client) et la carte de la commande.
        $fil = $this->actingAs($partenaire)->getJson("/api/v1/partenaire/produits/{$produit->id}/conversation")->assertOk()->json('data');
        $types = collect($fil['items'])->map(fn ($i) => $i['type'] === 'message' ? $i['donnee']['type'] : 'commande')->sort()->values()->all();
        $this->assertSame(['commande', TYPE_MESSAGE_COMMANDE_CREEE], $types);
        $this->assertSame(['recues' => 1, 'livrees' => 0, 'annulees' => 0, 'en_cours' => 1], $fil['en_tete']);
        $this->assertStringNotContainsString('prix_cession', json_encode($fil));

        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/produits/{$produit->id}/messages", ['contenu' => 'Stock dispo ce soir'])->assertCreated();
        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/commandes/{$commande['id']}/messages", ['contenu' => 'Client rappelé'])->assertCreated();
        $this->statut($partenaire, $commande, STATUT_COMMANDE_VALIDEE)->assertOk();

        // Suivi : création puis validation.
        $suivi = $this->actingAs($partenaire)->getJson("/api/v1/partenaire/commandes/{$commande['id']}/suivi")->assertOk()->json('data');
        $this->assertSame(['commande_creee', 'statut_change'], array_column($suivi, 'action'));
        $this->assertSame(STATUT_COMMANDE_VALIDEE, $suivi[1]['donnees']['statut_apres']);
        $this->actingAs($partenaire)->getJson("/api/v1/partenaire/commandes/{$commande['id']}")->assertOk()
            ->assertJsonPath('meta.apercu.total', 200000)->assertJsonPath('data.statut_commande', STATUT_COMMANDE_VALIDEE);

        // "Commandes uniquement" : compteurs par bucket.
        $this->actingAs($partenaire)->getJson('/api/v1/partenaire/commandes?statut=validee,en_preparation,en_livraison')->assertOk()
            ->assertJsonPath('data.compteurs.en_cours', 1)->assertJsonPath('data.compteurs.nouvelle', 0)->assertJsonPath('data.commandes.total', 1);

        // Le coordinateur voit tout et peut écrire, mais ne pilote pas le statut.
        $this->actingAs($coordinateur)->getJson("/api/v1/coordinateur/partenaires/{$partenaire->id}/produits-actifs")->assertOk()->assertJsonPath('data.0.produit_id', $produit->id);
        $this->assertCount(3, $this->actingAs($coordinateur)->getJson("/api/v1/coordinateur/partenaires/{$partenaire->id}/produits/{$produit->id}/conversation")->json('data.items'));
        $this->actingAs($coordinateur)->getJson("/api/v1/coordinateur/commandes-partenaire/{$commande['id']}/messages")->assertOk()->assertJsonPath('data.total', 1);
        $this->actingAs($coordinateur)->postJson("/api/v1/coordinateur/commandes-partenaire/{$commande['id']}/messages", ['contenu' => 'Bien reçu'])->assertCreated();

        // Le partenaire voit le message du coordinateur comme nouvelle activité.
        $this->actingAs($partenaire)->getJson("/api/v1/partenaire/commandes?statut=validee")->assertJsonPath('data.commandes.data.0.nouvelles_activites', 1);

        // Le fournisseur du produit ne voit jamais ces conversations, et les fils Ordi'Space restent vides.
        $fournisseur = User::find($produit->fournisseur_id);
        $this->actingAs($fournisseur)->getJson("/api/v1/coordinateur/commandes-partenaire/{$commande['id']}/messages")->assertForbidden();
        $this->actingAs($fournisseur)->getJson("/api/v1/coordinateur/partenaires/{$partenaire->id}/produits-actifs")->assertForbidden();
        $filOrdispace = $this->actingAs($fournisseur)->getJson("/api/v1/produits/{$produit->id}/conversation")->assertOk()->json('data.items');
        $this->assertStringNotContainsString('Stock dispo ce soir', json_encode($filOrdispace));
        $this->assertSame(0, \App\Models\Message::count());
    }

    public function test_commande_depuis_le_catalogue_en_stock_validee_sans_paiement(): void
    {
        $partenaire = $this->creerPartenaire();
        $produit = $this->ordinateur();
        $this->approvisionner($partenaire, $produit, 2);

        $reponse = $this->actingAs($partenaire)->postJson('/api/v1/partenaire/commandes', [
            'produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire' => 200000,
            'nom' => 'Konan Yao', 'telephone' => '0102030405', 'lieu' => 'Quartier Habitat, Yamoussoukro',
        ])->assertCreated()->json('data');

        $this->assertNull($reponse['paiement']);
        $this->assertSame(STATUT_COMMANDE_VALIDEE, $reponse['commande']['statut_commande']);
        $this->assertEquals(50000, $reponse['commande']['benefice']);
        $this->assertSame(1, Partenaire::find($partenaire->id)->quantiteEnStock($produit->id));

        // Compte client créé avec son numéro (suivi + SAV), retrouvé à la commande suivante.
        $client = User::where('telephone', '+2250102030405')->where('type_utilisateur', ROLE_CLIENT)->firstOrFail();
        $this->assertSame('Konan Yao', $client->nom);
        $this->actingAs($partenaire)->postJson('/api/v1/partenaire/commandes', [
            'produit_id' => $produit->id, 'quantite' => 1, 'nom' => 'Konan Yao', 'telephone' => '01 02 03 04 05', 'lieu' => 'Habitat',
        ])->assertCreated();
        $this->assertSame(1, User::where('telephone', '+2250102030405')->count());
        $this->assertSame(2, $this->actingAs($client)->getJson('/api/v1/commandes-partenaire')->json('data.total'));

        // Jamais sous le prix de cession.
        $this->actingAs($partenaire)->postJson('/api/v1/partenaire/commandes', [
            'produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire' => 100000, 'nom' => 'X', 'telephone' => '0102030406', 'lieu' => 'Y',
        ])->assertUnprocessable()->assertJsonValidationErrors('prix_unitaire', 'error.fields');
    }

    public function test_commande_sans_stock_paiement_hors_application_puis_validation_a_la_reception(): void
    {
        // Wave n'est qu'une formalité : aucun appel externe ne doit partir.
        \Illuminate\Support\Facades\Http::fake();

        $partenaire = $this->creerPartenaire();
        $produit = $this->ordinateur();
        $this->actingAs($partenaire)->putJson("/api/v1/partenaire/catalogue/{$produit->id}/offre", ['prix_revente' => 200000]);

        $reponse = $this->actingAs($partenaire)->postJson('/api/v1/partenaire/commandes', [
            'produit_id' => $produit->id, 'quantite' => 1, 'nom' => 'Awa Koné', 'telephone' => '0707070707', 'lieu' => 'Morofé',
        ])->assertCreated()->json('data');

        $this->assertSame(STATUT_COMMANDE_EN_ATTENTE, $reponse['commande']['statut_commande']);
        $this->assertEquals(200000, $reponse['commande']['montant_total']); // son prix de revente par défaut
        $this->assertEquals(150000, $reponse['paiement']['montant']);       // il paie le prix de cession
        $apprId = $reponse['paiement']['approvisionnement_id'];

        // "Payer" sur l'écran Confirmation : paiement fait hors application, déclaré une seule fois.
        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/approvisionnements/{$apprId}/confirmer")
            ->assertOk()->assertJsonPath('data.statut', STATUT_APPRO_PAIEMENT_DECLARE);
        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/approvisionnements/{$apprId}/confirmer")->assertUnprocessable();
        \Illuminate\Support\Facades\Http::assertNothingSent();

        // L'Admin vérifie le paiement reçu, valide puis expédie.
        $admin = $this->creerAdmin();
        $this->actingAs($admin)->postJson("/api/v1/admin/approvisionnements-partenaires/{$apprId}/valider")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/admin/approvisionnements-partenaires/{$apprId}/expedier")->assertOk();
        $this->actingAs($partenaire)->postJson("/api/v1/partenaire/approvisionnements/{$apprId}/reception")->assertOk();

        // Le stock est arrivé : la commande du client est validée, le stock aussitôt sorti.
        $this->actingAs($partenaire)->getJson("/api/v1/partenaire/commandes/{$reponse['commande']['id']}")
            ->assertJsonPath('data.statut_commande', STATUT_COMMANDE_VALIDEE);
        $this->assertSame(0, Partenaire::find($partenaire->id)->quantiteEnStock($produit->id));
    }

    public function test_catalogue_du_partenaire_filtres_de_la_marketplace(): void
    {
        $partenaire = $this->creerPartenaire();
        $hp = $this->ordinateur(['nom_produit' => 'HP 840', 'marque' => 'HP']);
        $this->ordinateur(['nom_produit' => 'Dell 5410', 'marque' => 'DELL']);

        $resultats = $this->actingAs($partenaire)->getJson('/api/v1/partenaire/catalogue?marques[]=HP')->assertOk()->json('data.data');
        $this->assertSame([$hp->id], array_column($resultats, 'id'));
        $this->assertArrayHasKey('processeur', $resultats[0]);
    }

    public function test_profil_du_partenaire_lecture_et_modification(): void
    {
        $partenaire = $this->creerPartenaire();

        $this->actingAs($partenaire)->getJson('/api/v1/partenaire/profil')->assertOk()
            ->assertJsonPath('data.contact_pro', '+2250701020304') // repli : numéro de connexion
            ->assertJsonPath('data.zone_couverte', 'Yamoussoukro');

        $this->actingAs($partenaire)->patchJson('/api/v1/partenaire/profil', [
            'horaires_ouverture' => 'Lun–Sam 8h–19h', 'nom_gerant' => 'Edi Diaby', 'telephone_gerant' => '0700000101',
            'nom_boutique' => 'Tentative', 'remise_defaut' => 80, // ignorés : fixés par l'Admin
        ])->assertOk()->assertJsonPath('data.horaires_ouverture', 'Lun–Sam 8h–19h')->assertJsonPath('data.nom_gerant', 'Edi Diaby');

        $this->assertNotSame('Tentative', Partenaire::find($partenaire->id)->nom_boutique);
        $this->assertEquals(0, Partenaire::find($partenaire->id)->remise_defaut);
        $this->actingAs($partenaire)->patchJson('/api/v1/partenaire/profil', ['lien_maps' => 'javascript:alert(1)'])->assertUnprocessable();
    }

    public function test_statistiques_du_partenaire(): void
    {
        $partenaire = $this->creerPartenaire();
        $produit = $this->ordinateur();
        $this->actingAs($partenaire)->putJson("/api/v1/partenaire/catalogue/{$produit->id}/offre", ['prix_revente' => 200000]);
        $this->approvisionner($partenaire, $produit, 3);
        $client = $this->creerClient();

        foreach ([STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_REPORTEE] as $statut) {
            $c = $this->commander($client, $partenaire, $produit);
            $this->statut($partenaire, $c, $statut)->assertOk();
        }
        $annulee = $this->commander($client, $partenaire, $produit);
        $this->statut($partenaire, $annulee, STATUT_COMMANDE_ANNULEE, 'Désistement')->assertOk();

        $stats = $this->actingAs($partenaire)->getJson('/api/v1/partenaire/statistiques?periode=semaine')->assertOk()->json('data');

        $this->assertEquals(400000, $stats['chiffre_affaires']);
        $this->assertNull($stats['croissance_pourcentage']); // aucune vente la semaine dernière
        $this->assertSame(2, $stats['produits_vendus']);
        $this->assertSame(5, $stats['commandes_recues']);
        $this->assertSame(3, $stats['commandes_validees']); // validée + 2 livrées
        $this->assertSame(1, $stats['commandes_annulees']);
        $this->assertSame(1, $stats['commandes_en_attente']); // reportée
        $this->assertEquals(100000, $stats['benefice']);
        $this->assertSame([['produit_id' => $produit->id, 'quantite_vendue' => 2, 'montant' => 400000]],
            array_map(fn ($p) => array_intersect_key($p, array_flip(['produit_id', 'quantite_vendue', 'montant'])), $stats['produits_plus_vendus']));
    }

    public function test_parametres_de_periode_invalides_refuses_sans_erreur_serveur(): void
    {
        $partenaire = $this->creerPartenaire();

        foreach (['accueil', 'statistiques'] as $ecran) {
            $this->actingAs($partenaire)->getJson("/api/v1/partenaire/{$ecran}?date_debut=abc&date_fin=xyz")->assertUnprocessable();
            $this->actingAs($partenaire)->getJson("/api/v1/partenaire/{$ecran}?periode=nimportequoi")->assertUnprocessable();
            $this->actingAs($partenaire)->getJson("/api/v1/partenaire/{$ecran}?date_debut=2026-11-01&date_fin=2026-11-30")->assertOk();
        }
    }

    public function test_conversations_partenaires_reservees_a_l_espace_partenaires_pour_un_admin_restreint(): void
    {
        $partenaire = $this->creerPartenaire();

        $this->actingAs($this->creerAdminRestreint(['clients']))->getJson('/api/v1/coordinateur/partenaires')->assertForbidden();
        $this->actingAs($this->creerAdminRestreint(['clients']))->getJson("/api/v1/coordinateur/partenaires/{$partenaire->id}/produits-actifs")->assertForbidden();
        $this->actingAs($this->creerAdminRestreint(['partenaires']))->getJson('/api/v1/coordinateur/partenaires')->assertOk();
        $this->actingAs($this->creerCoordinateur())->getJson('/api/v1/coordinateur/partenaires')->assertOk();
    }
}
