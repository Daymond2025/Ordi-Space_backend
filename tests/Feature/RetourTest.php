<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\Retour;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

class RetourTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function creerLivreur(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id]);

        return $user;
    }

    private function ouvrirRetour(): Retour
    {
        $client = $this->creerClient();
        $ligne = $this->creerAchatLivre($client);

        $reponse = $this->actingAs($client)->postJson("/api/v1/lignes-commande/{$ligne->id}/retour", ['motif' => 'Produit défectueux']);
        $reponse->assertCreated();

        return Retour::findOrFail($reponse->json('data.id'));
    }

    public function test_le_fournisseur_ne_voit_que_ses_propres_retours(): void
    {
        $retourA = $this->ouvrirRetour();
        $retourB = $this->ouvrirRetour();

        $fournisseurA = User::find($retourA->fournisseur_id);
        $liste = $this->actingAs($fournisseurA)->getJson('/api/v1/retours');

        $ids = collect($liste->json('data.data') ?? $liste->json('data'))->pluck('id');
        $this->assertContains($retourA->id, $ids);
        $this->assertNotContains($retourB->id, $ids);
    }

    public function test_le_client_ne_voit_que_ses_propres_retours(): void
    {
        $client = $this->creerClient();
        $ligne = $this->creerAchatLivre($client);
        $this->actingAs($client)->postJson("/api/v1/lignes-commande/{$ligne->id}/retour", ['motif' => 'Colis endommagé'])->assertCreated();

        $autreRetour = $this->ouvrirRetour();

        $liste = $this->actingAs($client)->getJson('/api/v1/retours');
        $ids = collect($liste->json('data.data') ?? $liste->json('data'))->pluck('id');

        $this->assertNotContains($autreRetour->id, $ids);
    }

    public function test_un_livreur_ne_peut_pas_lister_les_retours(): void
    {
        $this->ouvrirRetour();
        $livreur = $this->creerLivreur();

        $this->actingAs($livreur)->getJson('/api/v1/retours')->assertForbidden();
    }

    public function test_le_coordinateur_voit_tous_les_retours(): void
    {
        $retourA = $this->ouvrirRetour();
        $retourB = $this->ouvrirRetour();
        $coordinateur = $this->creerCoordinateur();

        $liste = $this->actingAs($coordinateur)->getJson('/api/v1/retours');
        $ids = collect($liste->json('data.data') ?? $liste->json('data'))->pluck('id');

        $this->assertContains($retourA->id, $ids);
        $this->assertContains($retourB->id, $ids);
    }
}
