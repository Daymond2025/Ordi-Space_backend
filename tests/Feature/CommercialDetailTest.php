<?php

namespace Tests\Feature;

use App\Models\CanalVente;
use App\Models\Commande;
use App\Models\Commercial;
use App\Models\LigneCommande;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * GET /coordinateur/commerciaux/{commercial} (profil) et
 * PATCH /coordinateur/commerciaux/{commercial}/statut (activer/suspendre) —
 * écran "Profil commercial" (Espace Agent).
 */
class CommercialDetailTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function creerCommandePourCommercial(User $commercial, string $statut, ?float $commissionAgent = null): Commande
    {
        $client = $this->creerClient();
        $produit = $this->creerProduitPhysique(['commission_agent' => $commissionAgent]);
        $canal = CanalVente::firstOrCreate(['nom_canal' => 'Boutique en ligne']);

        $commande = Commande::create([
            'client_id' => $client->id,
            'commercial_id' => $commercial->id,
            'canal_vente_id' => $canal->id,
            'statut_commande' => $statut,
            'montant_total' => $produit->prix,
            'date_commande' => now(),
        ]);

        LigneCommande::create([
            'commande_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 1,
            'prix_unitaire' => $produit->prix,
        ]);

        return $commande;
    }

    public function test_le_profil_expose_les_infos_et_les_statistiques(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercialUser = $this->creerCommercial();
        Commercial::where('user_id', $commercialUser->id)->update([
            'nom_entreprise' => 'Ebe com',
            'localisation' => 'Gagnoa, au commerce',
        ]);

        $this->creerCommandePourCommercial($commercialUser, STATUT_COMMANDE_LIVREE, 1500);
        $this->creerCommandePourCommercial($commercialUser, STATUT_COMMANDE_VALIDEE, 9999);
        $this->creerCommandePourCommercial($commercialUser, STATUT_COMMANDE_ANNULEE);

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/coordinateur/commerciaux/{$commercialUser->id}");

        $reponse->assertOk();
        $donnees = $reponse->json('data');
        $this->assertSame($commercialUser->id, $donnees['user_id']);
        $this->assertTrue($donnees['actif']);
        $this->assertSame('Ebe com', $donnees['nom_entreprise']);
        $this->assertSame('Gagnoa, au commerce', $donnees['localisation']);
        $this->assertSame(3, $donnees['statistiques']['commandes_total']);
        $this->assertSame(1, $donnees['statistiques']['commandes_validees']);
        $this->assertSame(1, $donnees['statistiques']['commandes_annulees']);
        $this->assertEquals(1500, $donnees['statistiques']['commission_totale']);
    }

    public function test_le_coordinateur_peut_suspendre_puis_reactiver(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercialUser = $this->creerCommercial();

        $this->actingAs($coordinateur)
            ->patchJson("/api/v1/coordinateur/commerciaux/{$commercialUser->id}/statut", ['actif' => false])
            ->assertOk();
        $this->assertSame(STATUT_COMPTE_SUSPENDU, $commercialUser->fresh()->statut_compte);

        $this->actingAs($coordinateur)
            ->patchJson("/api/v1/coordinateur/commerciaux/{$commercialUser->id}/statut", ['actif' => true])
            ->assertOk();
        $this->assertSame(STATUT_COMPTE_ACTIF, $commercialUser->fresh()->statut_compte);
    }

    public function test_un_commercial_ne_peut_pas_suspendre_un_collegue(): void
    {
        $commercial = $this->creerCommercial();
        $autreCommercial = $this->creerCommercial();

        $this->actingAs($commercial)
            ->patchJson("/api/v1/coordinateur/commerciaux/{$autreCommercial->id}/statut", ['actif' => false])
            ->assertForbidden();
    }

    public function test_un_commercial_ne_peut_pas_consulter_un_profil(): void
    {
        $commercial = $this->creerCommercial();
        $autreCommercial = $this->creerCommercial();

        $this->actingAs($commercial)
            ->getJson("/api/v1/coordinateur/commerciaux/{$autreCommercial->id}")
            ->assertForbidden();
    }
}
