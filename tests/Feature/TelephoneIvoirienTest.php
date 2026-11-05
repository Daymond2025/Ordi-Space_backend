<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Numéros ivoiriens à 10 chiffres : le 0 initial fait partie du numéro —
 * sans lui, wa.me (bouton WhatsApp de la fiche client Admin) et l'OTP
 * WhatsApp visent un numéro qui n'existe pas.
 */
class TelephoneIvoirienTest extends TestCase
{
    use RefreshDatabase;

    public function test_tous_les_formats_saisis_donnent_le_meme_numero_avec_le_zero(): void
    {
        foreach (['0564220887', '05 64 22 08 87', '+225 05 64 22 08 87', '2250564220887', '002250564220887', '+225564220887', '225564220887'] as $saisie) {
            $this->assertSame('+2250564220887', normaliser_telephone($saisie), $saisie);
        }
    }

    public function test_le_lien_whatsapp_contient_le_zero(): void
    {
        $this->assertSame('https://wa.me/2250564220887', lien_whatsapp('+225564220887'));
    }

    public function test_la_migration_restaure_le_zero_des_numeros_deja_enregistres(): void
    {
        $sansZero = User::factory()->create(['type_utilisateur' => ROLE_CLIENT, 'telephone' => '+225564220887']);
        $correct = User::factory()->create(['type_utilisateur' => ROLE_CLIENT, 'telephone' => '+2250759028545']);
        // Doublon du même client déjà au bon format : laissé tel quel (fusion manuelle).
        $doublon = User::factory()->create(['type_utilisateur' => ROLE_CLIENT, 'telephone' => '+225759028545']);

        (require database_path('migrations/2026_11_05_090000_restaure_zero_initial_telephones_ci.php'))->up();

        $this->assertSame('+2250564220887', $sansZero->fresh()->telephone);
        $this->assertSame('+2250759028545', $correct->fresh()->telephone);
        $this->assertSame('+225759028545', $doublon->fresh()->telephone);
    }

    public function test_un_client_enregistre_sans_zero_se_connecte_toujours_apres_correction(): void
    {
        User::factory()->create(['type_utilisateur' => ROLE_CLIENT, 'telephone' => '+225564220887']);
        (require database_path('migrations/2026_11_05_090000_restaure_zero_initial_telephones_ci.php'))->up();

        $this->assertTrue(User::where('telephone', normaliser_telephone('05 64 22 08 87'))->where('type_utilisateur', ROLE_CLIENT)->exists());
    }
}
