<?php

namespace Tests\Feature;

use App\Models\FraisLivraisonProduit;
use App\Models\Localite;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * "Paramètres boutique" d'un produit déjà publié (ProduitController::
 * modifierBoutique()) — le fournisseur ne peut plus fixer prix_barre/
 * pourcentage_reduction/etat_produit depuis le retrait de ces champs de son
 * formulaire (StoreProduitRequest) ; publier() ne couvre que la première
 * validation, cette route couvre tout ajustement après coup. Plus de
 * commission_revente dédiée : le livreur a rejoint le maintenancier comme
 * "apporteur d'affaire" (retour du PDG), c'est désormais la même
 * commission_apporteur — voir VenteBoutique::enregistrer(). `frais_livraison`
 * ajouté (retour de test réel) : répare le vrai trou derrière le message
 * générique "Les données envoyées ne sont pas valides" que voyait le
 * Coordinateur en créant une commande pour un produit publié sans barème.
 */
class ProduitBoutiqueTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_le_coordinateur_ajuste_les_parametres_boutique_dun_produit_publie(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique([
            'statut_produit' => STATUT_PRODUIT_VALIDE,
            'prix' => 100000,
            'prix_vente' => 150000,
        ]);

        $reponse = $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/boutique", [
            'commission_apporteur' => 15000,
            'prix_barre' => 180000,
            'pourcentage_reduction' => 20,
            'etat_produit' => 'occasion',
        ]);

        $reponse->assertOk();
        $produit->refresh();
        $this->assertEquals(15000, $produit->commission_apporteur);
        $this->assertEquals(180000, $produit->prix_barre);
        $this->assertSame(20, $produit->pourcentage_reduction);
        $this->assertSame('occasion', $produit->etat_produit);
        // prix_vente non envoyé : inchangé.
        $this->assertEquals(150000, $produit->prix_vente);
    }

    public function test_un_champ_omis_garde_sa_valeur_actuelle(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique([
            'statut_produit' => STATUT_PRODUIT_VALIDE,
            'prix_vente' => 150000,
            'commission_apporteur' => 10000,
        ]);

        $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/boutique", [
            'prix_vente' => 160000,
        ])->assertOk();

        $produit->refresh();
        $this->assertEquals(160000, $produit->prix_vente);
        $this->assertEquals(10000, $produit->commission_apporteur);
    }

    public function test_un_champ_envoye_a_null_efface_la_valeur_actuelle(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique([
            'statut_produit' => STATUT_PRODUIT_VALIDE,
            'prix_barre' => 180000,
            'pourcentage_reduction' => 20,
            'commission_agent' => 1000,
            'commission_apporteur' => 15000,
        ]);

        // Les 4 champs effaçables de la feuille, pas seulement les 2 déjà
        // nullable avant ce test (retour de test réel : FeuilleBoutiqueProduit.tsx
        // envoie `null` pour commission_apporteur dès que son champ est vidé —
        // commission_agent n'a pas de champ dédié dans cette feuille mais doit
        // rester cohérent avec publier(), qui le traite déjà comme nullable).
        $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/boutique", [
            'prix_barre' => null,
            'pourcentage_reduction' => null,
            'commission_agent' => null,
            'commission_apporteur' => null,
        ])->assertOk();

        $produit->refresh();
        $this->assertNull($produit->prix_barre);
        $this->assertNull($produit->pourcentage_reduction);
        $this->assertNull($produit->commission_agent);
        $this->assertNull($produit->commission_apporteur);
    }

    public function test_refuse_tant_que_le_produit_nest_pas_encore_publie(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_EN_ATTENTE]);

        $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/boutique", [
            'commission_apporteur' => 15000,
        ])->assertForbidden();
    }

    public function test_un_fournisseur_ne_peut_pas_appeler_cette_route(): void
    {
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_VALIDE]);
        $proprietaire = \App\Models\User::findOrFail($produit->fournisseur_id);

        $this->actingAs($proprietaire)->patchJson("/api/v1/produits/{$produit->id}/boutique", [
            'commission_apporteur' => 15000,
        ])->assertForbidden();
    }

    public function test_le_coordinateur_definit_le_bareme_de_livraison_dun_produit_sans_frais(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_VALIDE]);
        $produit->fraisLivraison()->delete();
        $localite = Localite::first();

        $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/boutique", [
            'frais_livraison' => [
                ['localite_id' => $localite->id, 'montant' => 1500],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('frais_livraison_produits', [
            'produit_id' => $produit->id,
            'localite_id' => $localite->id,
            'montant' => 1500,
        ]);
    }

    public function test_le_nouveau_bareme_remplace_entierement_lancien(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_VALIDE]);
        $localites = Localite::take(2)->get();

        FraisLivraisonProduit::create(['produit_id' => $produit->id, 'localite_id' => $localites[0]->id, 'montant' => 9999]);

        $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/boutique", [
            'frais_livraison' => [
                ['localite_id' => $localites[1]->id, 'montant' => 2000],
            ],
        ])->assertOk();

        $this->assertSame(1, $produit->fraisLivraison()->count());
        $this->assertDatabaseMissing('frais_livraison_produits', ['produit_id' => $produit->id, 'localite_id' => $localites[0]->id]);
        $this->assertDatabaseHas('frais_livraison_produits', ['produit_id' => $produit->id, 'localite_id' => $localites[1]->id, 'montant' => 2000]);
    }

    public function test_bareme_omis_naltere_pas_les_frais_existants(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_VALIDE, 'prix_vente' => 150000]);
        $localite = Localite::first();

        FraisLivraisonProduit::create(['produit_id' => $produit->id, 'localite_id' => $localite->id, 'montant' => 1500]);

        $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/boutique", [
            'prix_vente' => 160000,
        ])->assertOk();

        $this->assertDatabaseHas('frais_livraison_produits', ['produit_id' => $produit->id, 'localite_id' => $localite->id, 'montant' => 1500]);
    }

    public function test_rejette_un_produit_numerique(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['statut_produit' => STATUT_PRODUIT_VALIDE, 'type_livraison' => TYPE_LIVRAISON_NUMERIQUE]);
        $localite = Localite::first();

        $this->actingAs($coordinateur)->patchJson("/api/v1/produits/{$produit->id}/boutique", [
            'frais_livraison' => [
                ['localite_id' => $localite->id, 'montant' => 1500],
            ],
        ])->assertUnprocessable();
    }
}
