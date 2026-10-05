<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * "Sur négocier prix il doit avoir un signal cercle rouge lorsque le
 * fournisseur reçoit un message de négociation du coordinateur" (retour de
 * test réel) — pointeur de lecture dédié (ConsultationNegociationPrix),
 * distinct de la discussion générale : consulter l'un ne doit pas marquer
 * l'autre comme lu.
 *
 * Vérifié directement sur Produit::negociationALirePar() (pas de round-trip
 * HTTP GET /produits/{id}) : cette route est publique (hors auth:sanctum),
 * et l'enchaîner avec un actingAs() d'un autre acteur dans un même test
 * déclenche un faux 403 sur l'appel protégé suivant — artefact du client de
 * test déjà documenté ailleurs dans la suite (ProduitTypeLivraisonEtPackTest),
 * jamais reproductible en production (chaque requête HTTP réelle est
 * indépendante). La route elle-même est couverte séparément ci-dessous par
 * test_lendpoint_expose_bien_le_champ.
 */
class NegociationPrixBadgeTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_le_badge_sallume_a_un_nouveau_message_puis_sefface_apres_consultation(): void
    {
        $fournisseur = $this->creerFournisseur();
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['fournisseur_id' => $fournisseur->id, 'prix' => 100000]);

        $this->assertFalse($produit->negociationALirePar($fournisseur));

        // `consulte_le`/`date_envoi` sont des colonnes à la précision de la
        // seconde (comme le reste des timestamps de l'app) — un $this->travel()
        // d'une seconde entre chaque étape garantit un ordre chronologique
        // réel même à l'exécution très rapide d'un test, sans quoi deux
        // événements tombés dans la même seconde seraient indiscernables.
        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix", [
            'prix_propose' => 90000,
        ])->assertCreated();

        $this->assertTrue($produit->negociationALirePar($fournisseur));

        // Consulter la discussion générale du produit ne doit PAS éteindre le badge de négociation (fils distincts).
        $this->travel(1)->second();
        $this->actingAs($fournisseur)->getJson("/api/v1/produits/{$produit->id}/messages")->assertOk();
        $this->assertTrue($produit->negociationALirePar($fournisseur));

        // Ouvrir l'écran de négociation éteint le badge.
        $this->travel(1)->second();
        $this->actingAs($fournisseur)->getJson("/api/v1/produits/{$produit->id}/negociation-prix")->assertOk();
        $this->assertFalse($produit->negociationALirePar($fournisseur));

        // Une nouvelle contre-offre du Coordinateur rallume le badge.
        $this->travel(1)->second();
        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix/messages", [
            'contenu' => 'Je propose 95000 FCFA.',
        ])->assertCreated();

        $this->assertTrue($produit->negociationALirePar($fournisseur));

        // Une réponse DU FOURNISSEUR lui-même ne doit jamais s'allumer un badge pour lui-même.
        $this->travel(1)->second();
        $this->actingAs($fournisseur)->getJson("/api/v1/produits/{$produit->id}/negociation-prix")->assertOk();
        $this->travel(1)->second();
        $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix/messages", [
            'contenu' => "D'accord pour 92000.",
        ])->assertCreated();

        $this->assertFalse($produit->negociationALirePar($fournisseur));
    }

    public function test_lendpoint_expose_bien_le_champ_pour_le_fournisseur_proprietaire(): void
    {
        $fournisseur = $this->creerFournisseur();
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['fournisseur_id' => $fournisseur->id, 'prix' => 100000]);

        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix", [
            'prix_propose' => 90000,
        ])->assertCreated();

        // GET public — un seul actingAs() dans ce test, aucun enchaînement d'acteurs.
        $reponse = $this->actingAs($fournisseur)->getJson("/api/v1/produits/{$produit->id}");
        $reponse->assertOk();
        $this->assertTrue($reponse->json('data.negociation_a_lire'));
    }

    public function test_le_champ_est_absent_pour_un_role_non_concerne(): void
    {
        $fournisseur = $this->creerFournisseur();
        $coordinateur = $this->creerCoordinateur();
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique(['fournisseur_id' => $fournisseur->id, 'prix' => 100000]);

        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix", [
            'prix_propose' => 90000,
        ])->assertCreated();

        // Un client qui consulte la même route publique n'est concerné par aucun badge.
        $reponse = $this->actingAs($client)->getJson("/api/v1/produits/{$produit->id}");
        $reponse->assertOk();
        $this->assertNull($reponse->json('data.negociation_a_lire'));
    }

    /**
     * Retour de test réel : "sur la page produit, négocier prix n'est pas
     * comme pour le fournisseur, alors que c'est le coordinateur qui commence
     * une négociation" — avant ce correctif, le badge n'était calculé QUE
     * pour le fournisseur propriétaire (voir le test ci-dessus avant sa
     * réécriture), alors que c'est le Coordinateur/Admin qui démarre le fil :
     * lui aussi doit être alerté des réponses du fournisseur.
     */
    public function test_le_badge_sallume_aussi_pour_le_coordinateur_a_la_reponse_du_fournisseur(): void
    {
        $fournisseur = $this->creerFournisseur();
        $coordinateur = $this->creerCoordinateur();
        $produit = $this->creerProduitPhysique(['fournisseur_id' => $fournisseur->id, 'prix' => 100000]);

        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix", [
            'prix_propose' => 90000,
        ])->assertCreated();

        // Sa propre proposition n'allume pas son propre badge.
        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}");
        $reponse->assertOk();
        $this->assertFalse($reponse->json('data.negociation_a_lire'));

        $this->travel(1)->second();
        $this->actingAs($fournisseur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix/messages", [
            'contenu' => "D'accord pour 92000.",
        ])->assertCreated();

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}");
        $reponse->assertOk();
        $this->assertTrue($reponse->json('data.negociation_a_lire'));

        // Ouvrir le fil éteint le badge, comme côté fournisseur.
        $this->travel(1)->second();
        $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/negociation-prix")->assertOk();

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}");
        $reponse->assertOk();
        $this->assertFalse($reponse->json('data.negociation_a_lire'));
    }
}
