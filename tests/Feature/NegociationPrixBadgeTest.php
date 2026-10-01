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
        $produit = $this->creerProduitPhysique(['fournisseur_id' => $fournisseur->id, 'prix' => 100000]);

        $this->actingAs($coordinateur)->postJson("/api/v1/produits/{$produit->id}/negociation-prix", [
            'prix_propose' => 90000,
        ])->assertCreated();

        // Le Coordinateur lui-même n'est pas concerné par ce badge (réservé au fournisseur propriétaire).
        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}");
        $reponse->assertOk();
        $this->assertNull($reponse->json('data.negociation_a_lire'));
    }
}
