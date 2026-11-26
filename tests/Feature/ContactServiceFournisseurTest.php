<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/** "Contactez le service" (app Fournisseur) — numéros fixés par l'Admin. */
class ContactServiceFournisseurTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_ladmin_fixe_le_contact_et_le_fournisseur_le_lit(): void
    {
        $fournisseur = $this->creerFournisseur();

        $this->actingAs($fournisseur)->getJson('/api/v1/contact-fournisseur')
            ->assertOk()->assertJsonPath('data.appel', null);

        $this->actingAs($fournisseur)->putJson('/api/v1/admin/parametres/contact-fournisseur', ['appel' => '0700000001', 'whatsapp' => '0700000002'])
            ->assertForbidden();

        $this->actingAs($this->creerAdmin())->putJson('/api/v1/admin/parametres/contact-fournisseur', ['appel' => '07 00 00 00 01', 'whatsapp' => '0700000002'])
            ->assertOk();

        $this->actingAs($fournisseur)->getJson('/api/v1/contact-fournisseur')
            ->assertOk()
            ->assertJsonPath('data.appel', normaliser_numero_ci('0700000001'))
            ->assertJsonPath('data.whatsapp', normaliser_numero_ci('0700000002'))
            ->assertJsonPath('data.whatsapp_url', lien_whatsapp('0700000002'));
    }

    public function test_un_numero_trop_court_est_refuse(): void
    {
        $this->actingAs($this->creerAdmin())->putJson('/api/v1/admin/parametres/contact-fournisseur', ['appel' => '123', 'whatsapp' => '0700000002'])
            ->assertUnprocessable()->assertJsonValidationErrors('appel', 'error.fields');
    }
}
