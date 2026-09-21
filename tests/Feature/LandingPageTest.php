<?php

namespace Tests\Feature;

use App\Models\Parametre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Page d'accueil publique ("/") : présentation d'Ordi'Space, accès à chaque
 * plateforme (adresses de config/plateformes.php) et partage en prospection.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    private function definirPlateformes(array $urls): void
    {
        config(['plateformes' => array_merge([
            'client' => null, 'livreur' => null, 'fournisseur' => null, 'commercial' => null,
        ], $urls)]);
    }

    public function test_la_page_d_accueil_est_publique_et_presente_toutes_les_plateformes(): void
    {
        $reponse = $this->get('/');

        $reponse->assertOk();
        foreach (['Espace Client', 'Espace Livreur', 'Espace Fournisseur', 'Espace Commercial'] as $nom) {
            $reponse->assertSee($nom);
        }
        $reponse->assertSee('Choisir mon espace');
    }

    public function test_les_applications_internes_ne_sont_pas_presentees_au_public(): void
    {
        $reponse = $this->get('/');

        $reponse->assertDontSee('Espace Coordinateur');
        $reponse->assertDontSee('Administration');
        $reponse->assertDontSee('admin.daymondboutique.com', false);
    }

    public function test_une_plateforme_configuree_est_un_lien_et_les_autres_sont_bientot_disponibles(): void
    {
        $this->definirPlateformes(['client' => 'https://client.exemple.test', 'livreur' => 'https://livreur.exemple.test']);

        $reponse = $this->get('/');

        $reponse->assertSee('href="https://client.exemple.test"', false);
        $reponse->assertSee('href="https://livreur.exemple.test"', false);
        // Fournisseur et commercial : pas d'adresse → « Bientôt disponible », jamais un lien vide.
        $this->assertSame(2, substr_count($reponse->getContent(), 'Bientôt disponible</span>'));
        $reponse->assertDontSee('href=""', false);
    }

    public function test_les_metadonnees_de_partage_sont_presentes(): void
    {
        $reponse = $this->get('/');

        $reponse->assertSee('property="og:title"', false);
        $reponse->assertSee('property="og:image"', false);
        $reponse->assertSee('images/landing/og-ordispace.png', false);
        $reponse->assertSee('name="twitter:card" content="summary_large_image"', false);
        $reponse->assertSee('rel="canonical"', false);
        $reponse->assertSee('https://wa.me/?text=', false);
    }

    public function test_le_contact_whatsapp_n_apparait_que_si_l_admin_a_regle_le_support(): void
    {
        $this->definirPlateformes([]);
        $this->get('/')->assertDontSee('Nous écrire sur WhatsApp')->assertDontSee('Me prévenir');

        Parametre::ecrire(PARAMETRE_SUPPORT_TELEPHONE, '+2250758849281');

        $this->get('/')
            ->assertSee('Nous écrire sur WhatsApp')
            ->assertSee('https://wa.me/2250758849281', false)
            ->assertSee('Me prévenir');
    }

    public function test_les_images_de_la_page_existent(): void
    {
        $this->assertFileExists(public_path('images/landing/og-ordispace.png'));
        $this->assertFileExists(public_path('images/landing/mascotte.png'));
    }
}
