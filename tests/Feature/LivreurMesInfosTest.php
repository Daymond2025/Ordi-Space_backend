<?php

namespace Tests\Feature;

use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\Commercial;
use App\Models\Livraison;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * Écran "Mes infos" du livreur : la fiche de son coordinateur (déduite de ses
 * missions) et le numéro du support Ordi'Space (réglé par l'Admin).
 */
class LivreurMesInfosTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerLivreur(): User
    {
        $user = User::factory()->create(['type_utilisateur' => ROLE_LIVREUR]);
        $user->assignRole(ROLE_LIVREUR);
        Livreur::create(['user_id' => $user->id, 'type_vehicule' => 'moto']);

        return $user;
    }

    /** Une mission validée par $coordinateur et confiée à $livreur. */
    private function confierMission(User $livreur, User $coordinateur, string $dateValidation): void
    {
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);
        $commercial = User::factory()->create(['type_utilisateur' => 'commercial']);
        Commercial::create(['user_id' => $commercial->id, 'type_commercial' => 'humain']);

        $commande = Commande::create([
            'client_id' => $client->id,
            'commercial_id' => $commercial->id,
            'canal_vente_id' => CanalVente::firstOrCreate(['nom_canal' => 'Boutique en ligne'])->id,
            'coordinateur_id' => $coordinateur->id,
            'statut_commande' => STATUT_COMMANDE_EN_LIVRAISON,
            'montant_total' => 100000,
            'date_commande' => now(),
            'date_validation' => $dateValidation,
        ]);
        Livraison::create([
            'commande_id' => $commande->id,
            'adresse_id' => $adresse->id,
            'livreur_id' => $livreur->id,
            'statut_livraison' => STATUT_LIVRAISON_ASSIGNEE,
        ]);
    }

    public function test_sans_mission_le_livreur_n_a_pas_de_coordinateur(): void
    {
        $reponse = $this->actingAs($this->creerLivreur())->getJson('/api/v1/moi/profil');

        $reponse->assertOk();
        $this->assertNull($reponse->json('data.coordinateur'));
    }

    public function test_le_livreur_voit_la_fiche_du_coordinateur_de_sa_derniere_mission(): void
    {
        $livreur = $this->creerLivreur();
        $ancien = $this->creerCoordinateur(['prenom' => 'Ancien', 'nom' => 'Coord']);
        $recent = $this->creerCoordinateur(['prenom' => 'Idriss', 'nom' => 'Cissé', 'telephone' => '+2250705881634']);
        $recent->coordinateur->update([
            'adresse' => 'Cocody, Riviera',
            'horaires' => 'Lun-Sam • 8h-18h',
            'zone_couverte' => 'Abidjan (25 km)',
        ]);

        $this->confierMission($livreur, $ancien, '2026-09-01 10:00:00');
        $this->confierMission($livreur, $recent, '2026-09-10 10:00:00');

        $coordinateur = $this->actingAs($livreur)->getJson('/api/v1/moi/profil')->json('data.coordinateur');

        $this->assertSame('Idriss Cissé', $coordinateur['nom']);
        $this->assertSame('+2250705881634', $coordinateur['telephone']);
        $this->assertSame('https://wa.me/2250705881634', $coordinateur['whatsapp_url']);
        $this->assertSame('Cocody, Riviera', $coordinateur['adresse']);
        $this->assertSame('Lun-Sam • 8h-18h', $coordinateur['horaires']);
        $this->assertSame('Abidjan (25 km)', $coordinateur['zone_couverte']);
    }

    public function test_le_profil_boutique_expose_la_meme_fiche_de_coordinateur(): void
    {
        $livreur = $this->creerLivreur();

        $this->assertNull($this->actingAs($livreur)->getJson('/api/v1/boutique/profil')->json('data.coordinateur'));

        $coordinateur = $this->creerCoordinateur(['prenom' => 'Idriss', 'nom' => 'Cissé', 'telephone' => '+2250705881634']);
        $coordinateur->coordinateur->update(['horaires' => 'Lun-Sam • 8h-18h']);
        $this->confierMission($livreur, $coordinateur, '2026-09-10 10:00:00');

        $viaBoutique = $this->actingAs($livreur)->getJson('/api/v1/boutique/profil')->json('data.coordinateur');
        $viaProfil = $this->actingAs($livreur)->getJson('/api/v1/moi/profil')->json('data.coordinateur');

        $this->assertSame('Idriss Cissé', $viaBoutique['nom']);
        $this->assertSame('Lun-Sam • 8h-18h', $viaBoutique['horaires']);
        $this->assertSame($viaProfil, $viaBoutique);
    }

    public function test_le_coordinateur_d_un_autre_livreur_n_est_pas_expose(): void
    {
        $livreur = $this->creerLivreur();
        $autre = $this->creerLivreur();
        $this->confierMission($autre, $this->creerCoordinateur(), '2026-09-10 10:00:00');

        $this->assertNull($this->actingAs($livreur)->getJson('/api/v1/moi/profil')->json('data.coordinateur'));
    }

    public function test_le_support_est_vide_tant_que_l_admin_ne_l_a_pas_renseigne(): void
    {
        $reponse = $this->actingAs($this->creerLivreur())->getJson('/api/v1/support');

        $reponse->assertOk();
        $this->assertNull($reponse->json('data.telephone'));
        $this->assertNull($reponse->json('data.whatsapp_url'));
    }

    public function test_l_admin_regle_le_numero_du_support_visible_par_tous_les_livreurs(): void
    {
        $reponse = $this->actingAs($this->creerAdmin())->putJson('/api/v1/admin/parametres/support', ['telephone' => '07 58 84 92 81']);

        $reponse->assertOk();
        $this->assertSame('+2250758849281', $reponse->json('data.telephone'));
        $this->assertSame('+2250758849281', Parametre::lire(PARAMETRE_SUPPORT_TELEPHONE));

        $vueLivreur = $this->actingAs($this->creerLivreur())->getJson('/api/v1/support');
        $this->assertSame('+2250758849281', $vueLivreur->json('data.telephone'));
        $this->assertSame('https://wa.me/2250758849281', $vueLivreur->json('data.whatsapp_url'));
    }

    public function test_seul_l_admin_peut_modifier_le_support_et_le_numero_est_valide(): void
    {
        $this->actingAs($this->creerLivreur())->putJson('/api/v1/admin/parametres/support', ['telephone' => '0758849281'])->assertForbidden();
        $this->assertNull(Parametre::lire(PARAMETRE_SUPPORT_TELEPHONE));

        $this->actingAs($this->creerAdmin())->putJson('/api/v1/admin/parametres/support', ['telephone' => 'abc'])->assertUnprocessable();
        $this->actingAs($this->creerAdmin())->putJson('/api/v1/admin/parametres/support', [])->assertUnprocessable();
    }

    public function test_le_support_exige_d_etre_connecte(): void
    {
        $this->getJson('/api/v1/support')->assertUnauthorized();
    }
}
