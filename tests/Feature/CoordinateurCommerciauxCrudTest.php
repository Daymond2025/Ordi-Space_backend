<?php

namespace Tests\Feature;

use App\Models\Commercial;
use App\Models\JournalAudit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * CRUD de l'équipe commerciale par le coordinateur (écran Agent) :
 * POST/PATCH/DELETE /coordinateur/commerciaux.
 */
class CoordinateurCommerciauxCrudTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_un_coordinateur_cree_un_commercial(): void
    {
        $coordinateur = $this->creerCoordinateur();

        $reponse = $this->actingAs($coordinateur)->postJson('/api/v1/coordinateur/commerciaux', [
            'nom' => 'Kone', 'prenom' => 'Awa', 'telephone' => '07 99 00 41 01',
            'categorie_commercial' => CATEGORIES_COMMERCIAL[0], 'localisation' => 'Cocody',
        ]);

        $reponse->assertCreated();
        $user = User::findOrFail($reponse->json('data.user_id'));
        $this->assertSame(ROLE_COMMERCIAL, $user->type_utilisateur);
        $this->assertSame(STATUT_COMPTE_ACTIF, $user->statut_compte);
        $this->assertTrue($user->hasRole(ROLE_COMMERCIAL));
        $this->assertSame(TYPE_COMMERCIAL_HUMAIN, Commercial::find($user->id)->type_commercial);
        $this->assertSame('Cocody', Commercial::find($user->id)->localisation);
        $this->assertTrue(JournalAudit::where('acteur_id', $coordinateur->id)->where('action', ACTION_COMMERCIAL_CREE)->exists());

        // Il apparaît dans la liste de l'écran Agent.
        $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/commerciaux')
            ->assertOk()
            ->assertJsonFragment(['user_id' => $user->id, 'nom' => 'Kone']);
    }

    public function test_un_numero_deja_utilise_par_un_commercial_est_refuse(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $this->creerCommercial(['telephone' => normaliser_telephone('0799004102')]);

        $this->actingAs($coordinateur)->postJson('/api/v1/coordinateur/commerciaux', [
            'nom' => 'Doublon', 'telephone' => '0799004102', 'categorie_commercial' => CATEGORIES_COMMERCIAL[0],
        ])->assertUnprocessable()->assertJsonPath('error.fields.telephone.0', 'Ce numéro est déjà utilisé par un autre commercial.');
    }

    public function test_un_coordinateur_modifie_un_commercial(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial(['nom' => 'Ancien']);

        $this->actingAs($coordinateur)->patchJson("/api/v1/coordinateur/commerciaux/{$commercial->id}", [
            'nom' => 'Nouveau', 'telephone' => '0799004103', 'nom_entreprise' => 'Credix', 'categorie_commercial' => CATEGORIES_COMMERCIAL[1],
        ])->assertOk();

        $this->assertSame('Nouveau', $commercial->fresh()->nom);
        $this->assertSame(normaliser_telephone('0799004103'), $commercial->fresh()->telephone);
        $this->assertSame('Credix', Commercial::find($commercial->id)->nom_entreprise);
        $this->assertSame(CATEGORIES_COMMERCIAL[1], Commercial::find($commercial->id)->categorie_commercial);
    }

    public function test_suppression_puis_numero_reutilisable(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial(['telephone' => normaliser_telephone('0799004104')]);
        $commercial->createToken('test');

        $this->actingAs($coordinateur)->deleteJson("/api/v1/coordinateur/commerciaux/{$commercial->id}")->assertOk();

        $this->assertSame(STATUT_COMPTE_SUPPRIME, $commercial->fresh()->statut_compte);
        $this->assertSame(0, $commercial->tokens()->count());

        // Plus dans la liste du coordinateur.
        $this->actingAs($coordinateur)->getJson('/api/v1/coordinateur/commerciaux')
            ->assertOk()
            ->assertJsonMissing(['user_id' => $commercial->id]);

        // Son numéro peut servir à un nouveau commercial.
        $this->actingAs($coordinateur)->postJson('/api/v1/coordinateur/commerciaux', [
            'nom' => 'Remplaçant', 'telephone' => '0799004104', 'categorie_commercial' => CATEGORIES_COMMERCIAL[0],
        ])->assertCreated();
        $this->assertNull($commercial->fresh()->telephone);
    }

    public function test_suspendre_ferme_les_sessions(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $commercial->createToken('test');

        $this->actingAs($coordinateur)->patchJson("/api/v1/coordinateur/commerciaux/{$commercial->id}/statut", ['actif' => false])->assertOk();

        $this->assertSame(STATUT_COMPTE_SUSPENDU, $commercial->fresh()->statut_compte);
        $this->assertSame(0, $commercial->tokens()->count());
    }

    public function test_un_commercial_ne_peut_pas_gerer_l_equipe(): void
    {
        $commercial = $this->creerCommercial();

        $this->actingAs($commercial)->postJson('/api/v1/coordinateur/commerciaux', [
            'nom' => 'X', 'telephone' => '0799004105', 'categorie_commercial' => CATEGORIES_COMMERCIAL[0],
        ])->assertForbidden();
    }
}
