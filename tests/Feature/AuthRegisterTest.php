<?php

namespace Tests\Feature;

use App\Models\Livreur;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * POST /auth/register — auto-inscription Fournisseur/Commercial/Livreur
 * (voir roles_auto_inscription()). Couvre en particulier la normalisation du
 * téléphone avant contrôle d'unicité (AuthController::register()), absente
 * jusqu'ici et qui faisait planter l'inscription sur un doublon (500 brut au
 * lieu d'une erreur de validation propre).
 */
class AuthRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake(IMAGE_PRODUIT_DISQUE);
    }

    private function donneesLivreur(array $override = []): array
    {
        return array_merge([
            'nom' => 'Koffi',
            'prenom' => 'Jean',
            'telephone' => '+225700000099',
            'email' => 'jean.koffi@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'type_utilisateur' => ROLE_LIVREUR,
            'photo' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
            'photo_permis' => UploadedFile::fake()->create('permis.jpg', 100, 'image/jpeg'),
            'photo_cni' => UploadedFile::fake()->create('cni.jpg', 100, 'image/jpeg'),
            'photo_carte_grise' => UploadedFile::fake()->create('carte_grise.jpg', 100, 'image/jpeg'),
        ], $override);
    }

    public function test_un_livreur_peut_s_auto_inscrire(): void
    {
        $reponse = $this->postJson('/api/v1/auth/register', $this->donneesLivreur());

        $reponse->assertCreated();
        $reponse->assertJsonPath('data.user.type_utilisateur', ROLE_LIVREUR);
        $utilisateur = User::where('email', 'jean.koffi@example.com')->firstOrFail();
        $this->assertNotNull($utilisateur->getRawOriginal('photo'));

        $livreur = Livreur::findOrFail($utilisateur->id);
        $this->assertNotNull($livreur->getRawOriginal('photo_permis'));
        $this->assertNotNull($livreur->getRawOriginal('photo_cni'));
        $this->assertNotNull($livreur->getRawOriginal('photo_carte_grise'));
    }

    /**
     * Décision PDG : le livreur manipule l'argent du client à la livraison —
     * photo de profil, permis, CNI et carte grise sont donc obligatoires dès
     * l'inscription (pas une étape ultérieure optionnelle), pour pouvoir
     * l'identifier formellement en cas de vol/litige.
     */
    public function test_l_inscription_du_livreur_est_rejetee_si_un_document_manque(): void
    {
        foreach (['photo', 'photo_permis', 'photo_cni', 'photo_carte_grise'] as $champ) {
            $donnees = $this->donneesLivreur();
            unset($donnees[$champ]);

            $reponse = $this->postJson('/api/v1/auth/register', $donnees);

            $reponse->assertUnprocessable();
            $this->assertArrayHasKey($champ, $reponse->json('error.fields'), "Le champ {$champ} aurait dû être requis.");
        }

        $this->assertFalse(User::where('email', 'jean.koffi@example.com')->exists());
    }

    public function test_les_documents_du_livreur_ne_sont_pas_exiges_pour_un_fournisseur(): void
    {
        $reponse = $this->postJson('/api/v1/auth/register', [
            'nom' => 'Diallo',
            'email' => 'diallo.fournisseur@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'type_utilisateur' => ROLE_FOURNISSEUR,
            'nom_entreprise' => 'Diallo Informatique',
        ]);

        $reponse->assertCreated();
    }

    public function test_l_inscription_est_rejetee_si_l_email_existe_deja(): void
    {
        $this->postJson('/api/v1/auth/register', $this->donneesLivreur())->assertCreated();

        $reponse = $this->postJson('/api/v1/auth/register', $this->donneesLivreur([
            'telephone' => '+2250700000098',
        ]));

        $reponse->assertUnprocessable();
        $this->assertArrayHasKey('email', $reponse->json('error.fields'));
    }

    /**
     * Le bug corrigé : un même numéro saisi sous un format différent
     * (espaces, préfixe local) devait planter en 500 (contrainte unique DB)
     * au lieu de renvoyer une erreur de validation propre sur "telephone".
     */
    public function test_l_inscription_est_rejetee_proprement_si_le_telephone_existe_deja_sous_un_autre_format(): void
    {
        $this->postJson('/api/v1/auth/register', $this->donneesLivreur())->assertCreated();

        $reponse = $this->postJson('/api/v1/auth/register', $this->donneesLivreur([
            'email' => 'autre.livreur@example.com',
            'telephone' => '07 00 00 00 99',
        ]));

        $reponse->assertUnprocessable();
        $this->assertArrayHasKey('telephone', $reponse->json('error.fields'));
    }
}
