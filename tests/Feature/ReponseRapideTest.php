<?php

namespace Tests\Feature;

use App\Models\ReponseRapide;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * "Réponse rapide" (app Commercial) — bibliothèque entièrement gérée par
 * l'Admin, lue par le Commercial. Voir ReponseRapideController.
 */
class ReponseRapideTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_un_commercial_voit_la_liste_complete_par_defaut(): void
    {
        $commercial = $this->creerCommercial();
        ReponseRapide::create(['titre' => 'Objection prix', 'contenu' => 'Texte A']);
        ReponseRapide::create(['titre' => 'Salutation', 'contenu' => 'Texte B']);

        $reponse = $this->actingAs($commercial)->getJson('/api/v1/reponses-rapides');

        $reponse->assertOk();
        $this->assertCount(2, $reponse->json('data'));
    }

    public function test_le_filtre_favoris_ne_garde_que_celles_marquees_par_ladmin(): void
    {
        $commercial = $this->creerCommercial();
        ReponseRapide::create(['titre' => 'Favorite', 'contenu' => 'Texte A', 'est_favori' => true]);
        ReponseRapide::create(['titre' => 'Normale', 'contenu' => 'Texte B', 'est_favori' => false]);

        $reponse = $this->actingAs($commercial)->getJson('/api/v1/reponses-rapides?filtre=favoris');

        $titres = collect($reponse->json('data'))->pluck('titre');
        $this->assertEquals(['Favorite'], $titres->all());
    }

    public function test_le_filtre_plus_utilises_trie_par_nombre_de_copies_decroissant(): void
    {
        $commercial = $this->creerCommercial();
        ReponseRapide::create(['titre' => 'Peu copiée', 'contenu' => 'Texte A', 'nombre_copies' => 2]);
        ReponseRapide::create(['titre' => 'Très copiée', 'contenu' => 'Texte B', 'nombre_copies' => 50]);

        $reponse = $this->actingAs($commercial)->getJson('/api/v1/reponses-rapides?filtre=plus_utilises');

        $titres = collect($reponse->json('data'))->pluck('titre');
        $this->assertEquals(['Très copiée', 'Peu copiée'], $titres->all());
    }

    public function test_copier_incremente_le_compteur_reel(): void
    {
        $commercial = $this->creerCommercial();
        $reponseRapide = ReponseRapide::create(['titre' => 'Objection prix', 'contenu' => 'Texte A', 'nombre_copies' => 3]);

        $reponse = $this->actingAs($commercial)->postJson("/api/v1/reponses-rapides/{$reponseRapide->id}/copie");

        $reponse->assertOk();
        $this->assertDatabaseHas('reponses_rapides', ['id' => $reponseRapide->id, 'nombre_copies' => 4]);
    }

    public function test_un_commercial_ne_peut_pas_creer_de_reponse_rapide(): void
    {
        $commercial = $this->creerCommercial();

        $this->actingAs($commercial)->postJson('/api/v1/reponses-rapides', [
            'titre' => 'Triché', 'contenu' => 'Texte',
        ])->assertForbidden();
    }

    public function test_un_commercial_ne_peut_pas_consulter_la_fiche_dedition(): void
    {
        $commercial = $this->creerCommercial();
        $reponseRapide = ReponseRapide::create(['titre' => 'Objection prix', 'contenu' => 'Texte A']);

        $this->actingAs($commercial)->getJson("/api/v1/reponses-rapides/{$reponseRapide->id}")->assertForbidden();
    }

    public function test_ladmin_peut_creer_modifier_et_supprimer_une_reponse_rapide(): void
    {
        $admin = $this->creerAdmin();

        $creation = $this->actingAs($admin)->postJson('/api/v1/reponses-rapides', [
            'titre' => 'Objection prix', 'contenu' => 'Texte initial', 'est_favori' => true,
        ]);
        $creation->assertCreated();
        $id = $creation->json('data.id');

        $this->actingAs($admin)->getJson("/api/v1/reponses-rapides/{$id}")->assertOk()->assertJsonPath('data.titre', 'Objection prix');

        $this->actingAs($admin)->putJson("/api/v1/reponses-rapides/{$id}", [
            'titre' => 'Objection prix (modifiée)', 'contenu' => 'Texte modifié',
        ])->assertOk();
        $this->assertDatabaseHas('reponses_rapides', ['id' => $id, 'titre' => 'Objection prix (modifiée)']);

        $this->actingAs($admin)->deleteJson("/api/v1/reponses-rapides/{$id}")->assertOk();
        $this->assertDatabaseMissing('reponses_rapides', ['id' => $id]);
    }
}
