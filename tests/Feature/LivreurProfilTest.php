<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * POST /moi/profil/photo et PATCH /moi/vehicule — deux champs de profil
 * jusqu'ici sans chemin d'écriture : la photo (aucun champ User avant) et le
 * type de véhicule du Livreur (colonne existante mais jamais éditable).
 */
class LivreurProfilTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake(IMAGE_PRODUIT_DISQUE);
    }

    private function creerLivreur(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id, 'type_vehicule' => 'moto']);

        return $user;
    }

    public function test_un_utilisateur_peut_ajouter_une_photo_de_profil(): void
    {
        $livreur = $this->creerLivreur();

        $reponse = $this->actingAs($livreur)->postJson('/api/v1/moi/profil/photo', [
            'photo' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
        ]);

        $reponse->assertOk();
        $chemin = $livreur->fresh()->getRawOriginal('photo');
        $this->assertNotNull($chemin);
        Storage::disk(IMAGE_PRODUIT_DISQUE)->assertExists($chemin);
        $this->assertNotNull($reponse->json('data.photo'));
    }

    public function test_remplacer_la_photo_supprime_l_ancienne(): void
    {
        $livreur = $this->creerLivreur();

        $this->actingAs($livreur)->postJson('/api/v1/moi/profil/photo', [
            'photo' => UploadedFile::fake()->create('premiere.jpg', 100, 'image/jpeg'),
        ]);
        $ancienChemin = $livreur->fresh()->getRawOriginal('photo');

        $this->actingAs($livreur)->postJson('/api/v1/moi/profil/photo', [
            'photo' => UploadedFile::fake()->create('seconde.jpg', 100, 'image/jpeg'),
        ]);
        $nouveauChemin = $livreur->fresh()->getRawOriginal('photo');

        $this->assertNotSame($ancienChemin, $nouveauChemin);
        Storage::disk(IMAGE_PRODUIT_DISQUE)->assertMissing($ancienChemin);
        Storage::disk(IMAGE_PRODUIT_DISQUE)->assertExists($nouveauChemin);
    }

    public function test_la_photo_rejette_un_fichier_qui_n_est_pas_une_image(): void
    {
        $livreur = $this->creerLivreur();

        $reponse = $this->actingAs($livreur)->postJson('/api/v1/moi/profil/photo', [
            'photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ]);

        $reponse->assertUnprocessable();
        $this->assertNull($livreur->fresh()->getRawOriginal('photo'));
    }

    public function test_le_livreur_peut_definir_son_type_de_vehicule(): void
    {
        $livreur = $this->creerLivreur();

        $reponse = $this->actingAs($livreur)->patchJson('/api/v1/moi/vehicule', ['type_vehicule' => 'tricycle']);

        $reponse->assertOk();
        $this->assertSame('tricycle', $livreur->livreur->fresh()->type_vehicule);
    }

    public function test_le_type_de_vehicule_doit_faire_partie_de_la_liste_autorisee(): void
    {
        $livreur = $this->creerLivreur();

        $this->actingAs($livreur)->patchJson('/api/v1/moi/vehicule', ['type_vehicule' => 'camion'])
            ->assertUnprocessable();

        $this->assertSame('moto', $livreur->livreur->fresh()->type_vehicule);
    }

    public function test_un_client_ne_peut_pas_definir_un_type_de_vehicule(): void
    {
        $client = $this->creerClient();

        $this->actingAs($client)->patchJson('/api/v1/moi/vehicule', ['type_vehicule' => 'moto'])
            ->assertForbidden();
    }

    public function test_le_profil_du_livreur_expose_sa_zone_de_couverture(): void
    {
        $livreur = $this->creerLivreur();
        $livreur->livreur->update(['zone_couverture' => 'Abidjan, Palmeraie']);

        $this->actingAs($livreur)->getJson('/api/v1/moi/profil')
            ->assertOk()
            ->assertJsonPath('data.zone_couverture', 'Abidjan, Palmeraie')
            ->assertJsonPath('data.type_vehicule', 'moto');
    }
}
