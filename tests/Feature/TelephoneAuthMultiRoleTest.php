<?php

namespace Tests\Feature;

use App\Models\Commercial;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Uniformisation de l'auth : Fournisseur/Commercial/Livreur s'inscrivent et
 * se connectent désormais par téléphone + OTP WhatsApp, comme le Client —
 * TelephoneAuthController généralisé (voir TelephoneAuthTest.php pour le
 * comportement Client lui-même, inchangé). Coordinateur/Administrateur
 * restent hors de ce périmètre (AuthController, intact). Le Technicien de
 * maintenance est un cas à part : connexion par téléphone+OTP comme les
 * autres, mais sans auto-inscription (compte provisionné par l'Admin) —
 * voir les deux derniers tests.
 */
class TelephoneAuthMultiRoleTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake(IMAGE_PRODUIT_DISQUE);
    }

    public function test_un_fournisseur_peut_sinscrire_et_se_reconnecter_par_telephone(): void
    {
        $demande = $this->postJson('/api/v1/auth/telephone/otp', [
            'telephone' => '07 00 00 01 01',
            'type_utilisateur' => ROLE_FOURNISSEUR,
        ]);
        $demande->assertOk();
        $this->assertFalse($demande->json('data.compte_existant'));

        $inscription = $this->postJson('/api/v1/auth/telephone/inscription', [
            'telephone' => '07 00 00 01 01',
            'nom' => 'Kouadio',
            'prenom' => 'Awa',
            'type_utilisateur' => ROLE_FOURNISSEUR,
            'nom_entreprise' => 'Kouadio Informatique',
        ]);
        $inscription->assertCreated();
        $userId = $inscription->json('data.user_id');
        $code = $inscription->json('data.code_debug');

        $user = User::findOrFail($userId);
        $this->assertSame(ROLE_FOURNISSEUR, $user->type_utilisateur);
        $this->assertNull($user->email);
        $this->assertSame('+225700000101', $user->telephone);
        $this->assertDatabaseHas('fournisseurs', ['user_id' => $userId, 'nom_entreprise' => 'Kouadio Informatique']);

        $verification = $this->postJson('/api/v1/auth/verify-otp', [
            'user_id' => $userId,
            'code' => $code,
            'device_name' => 'test',
        ]);
        $verification->assertOk();
        $this->assertSame($userId, $verification->json('data.user.id'));

        // Reconnexion ultérieure : le numéro est désormais connu pour ce rôle.
        $reconnexion = $this->postJson('/api/v1/auth/telephone/otp', [
            'telephone' => '0700000101',
            'type_utilisateur' => ROLE_FOURNISSEUR,
        ]);
        $reconnexion->assertOk();
        $this->assertTrue($reconnexion->json('data.compte_existant'));
        $this->assertSame($userId, $reconnexion->json('data.user_id'));
    }

    public function test_un_commercial_peut_sinscrire_par_telephone_sans_champ_supplementaire(): void
    {
        $inscription = $this->postJson('/api/v1/auth/telephone/inscription', [
            'telephone' => '07 00 00 02 02',
            'nom' => 'Traore',
            'type_utilisateur' => ROLE_COMMERCIAL,
        ]);

        $inscription->assertCreated();
        $userId = $inscription->json('data.user_id');

        $this->assertDatabaseHas('users', ['id' => $userId, 'type_utilisateur' => ROLE_COMMERCIAL]);
        $this->assertDatabaseHas('commerciaux', ['user_id' => $userId, 'type_commercial' => TYPE_COMMERCIAL_HUMAIN]);
    }

    public function test_un_livreur_doit_fournir_ses_documents_pour_sinscrire_par_telephone(): void
    {
        $sansDocuments = $this->postJson('/api/v1/auth/telephone/inscription', [
            'telephone' => '07 00 00 03 03',
            'nom' => 'Bamba',
            'type_utilisateur' => ROLE_LIVREUR,
        ]);
        $sansDocuments->assertUnprocessable();
        $this->assertArrayHasKey('photo_permis', $sansDocuments->json('error.fields'));
        $this->assertArrayHasKey('photo_cni', $sansDocuments->json('error.fields'));
        $this->assertArrayHasKey('photo_carte_grise', $sansDocuments->json('error.fields'));
        $this->assertArrayHasKey('photo', $sansDocuments->json('error.fields'));

        $avecDocuments = $this->post('/api/v1/auth/telephone/inscription', [
            'telephone' => '07 00 00 03 03',
            'nom' => 'Bamba',
            'type_utilisateur' => ROLE_LIVREUR,
            'photo' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
            'photo_permis' => UploadedFile::fake()->create('permis.jpg', 100, 'image/jpeg'),
            'photo_cni' => UploadedFile::fake()->create('cni.jpg', 100, 'image/jpeg'),
            'photo_carte_grise' => UploadedFile::fake()->create('carte_grise.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json']);

        $avecDocuments->assertCreated();
        $userId = $avecDocuments->json('data.user_id');
        $livreur = Livreur::where('user_id', $userId)->firstOrFail();
        $this->assertNotNull($livreur->getRawOriginal('photo_permis'));
        $this->assertNotNull($livreur->getRawOriginal('photo_cni'));
        $this->assertNotNull($livreur->getRawOriginal('photo_carte_grise'));
    }

    public function test_deux_roles_differents_partageant_le_meme_numero_sont_resolus_independamment(): void
    {
        $livreur = $this->postJson('/api/v1/auth/telephone/inscription', [
            'telephone' => '07 00 00 04 04',
            'nom' => 'Yao',
            'type_utilisateur' => ROLE_LIVREUR,
            'photo' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
            'photo_permis' => UploadedFile::fake()->create('permis.jpg', 100, 'image/jpeg'),
            'photo_cni' => UploadedFile::fake()->create('cni.jpg', 100, 'image/jpeg'),
            'photo_carte_grise' => UploadedFile::fake()->create('carte_grise.jpg', 100, 'image/jpeg'),
        ])->json('data.user_id');

        // Même numéro, rôle différent — n'entre jamais en collision (unicité
        // scopée par rôle, voir la migration unicite_email_telephone_par_role).
        $fournisseur = $this->postJson('/api/v1/auth/telephone/inscription', [
            'telephone' => '07 00 00 04 04',
            'nom' => 'Yao',
            'type_utilisateur' => ROLE_FOURNISSEUR,
            'nom_entreprise' => 'Yao Distribution',
        ])->json('data.user_id');

        $this->assertNotSame($livreur, $fournisseur);

        $demandeLivreur = $this->postJson('/api/v1/auth/telephone/otp', [
            'telephone' => '0700000404', 'type_utilisateur' => ROLE_LIVREUR,
        ]);
        $this->assertSame($livreur, $demandeLivreur->json('data.user_id'));

        $demandeFournisseur = $this->postJson('/api/v1/auth/telephone/otp', [
            'telephone' => '0700000404', 'type_utilisateur' => ROLE_FOURNISSEUR,
        ]);
        $this->assertSame($fournisseur, $demandeFournisseur->json('data.user_id'));
    }

    public function test_un_technicien_provisionne_par_ladmin_peut_se_connecter_par_telephone(): void
    {
        $technicien = $this->creerTechnicien(['telephone' => '+225700000505']);

        $demande = $this->postJson('/api/v1/auth/telephone/otp', [
            'telephone' => '07 00 00 05 05',
            'type_utilisateur' => ROLE_TECHNICIEN_MAINTENANCE,
        ]);
        $demande->assertOk();
        $this->assertTrue($demande->json('data.compte_existant'));
        $this->assertSame($technicien->id, $demande->json('data.user_id'));

        $verification = $this->postJson('/api/v1/auth/verify-otp', [
            'user_id' => $technicien->id,
            'code' => $demande->json('data.code_debug'),
            'device_name' => 'test',
        ]);
        $verification->assertOk();
        $this->assertSame($technicien->id, $verification->json('data.user.id'));
    }

    public function test_un_technicien_ne_peut_pas_sauto_inscrire_par_telephone(): void
    {
        $inscription = $this->postJson('/api/v1/auth/telephone/inscription', [
            'telephone' => '07 00 00 06 06',
            'nom' => 'Kouamé',
            'type_utilisateur' => ROLE_TECHNICIEN_MAINTENANCE,
        ]);

        $inscription->assertUnprocessable();
        $this->assertArrayHasKey('type_utilisateur', $inscription->json('error.fields'));
    }
}
