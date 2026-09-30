<?php

namespace Tests\Feature;

use App\Models\NotificationOrdispace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * L'admin ("œil central") reçoit une vraie notification (table
 * notifications_ordispace) pour les infos entrantes significatives —
 * NotificationAdminService, branché sur 4 points d'entrée. Consultées/
 * marquées lues via GET/PATCH /moi/notifications (MoiController), déjà
 * génériques à tout rôle, aucun changement nécessaire là.
 */
class NotificationsAdminTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_tous_les_admins_sont_notifies_quand_un_fournisseur_soumet_un_produit(): void
    {
        $admin1 = $this->creerAdmin(['email' => 'admin1@example.com']);
        $admin2 = $this->creerAdmin(['email' => 'admin2@example.com']);
        $fournisseurUser = $this->creerFournisseur();
        $categorie = \App\Models\Categorie::first() ?? \App\Models\Categorie::create(['nom_categorie' => 'Test']);

        $this->actingAs($fournisseurUser)->postJson('/api/v1/produits', [
            'categorie_id' => $categorie->id,
            'nom_produit' => 'VERIF NOTIF - Produit test',
            'prix' => 100000,
            'quantite_stock' => 5,
            'type_livraison' => 'physique',
        ])->assertCreated();

        $this->assertSame(1, NotificationOrdispace::where('user_id', $admin1->id)->where('type_notification', 'produit_a_valider')->count());
        $this->assertSame(1, NotificationOrdispace::where('user_id', $admin2->id)->where('type_notification', 'produit_a_valider')->count());
    }

    public function test_aucune_notification_quand_ladmin_publie_lui_meme_un_produit(): void
    {
        $admin = $this->creerAdmin();
        $categorie = \App\Models\Categorie::first() ?? \App\Models\Categorie::create(['nom_categorie' => 'Test']);

        $this->actingAs($admin)->postJson('/api/v1/produits', [
            'categorie_id' => $categorie->id,
            'nom_produit' => 'VERIF NOTIF - Produit admin',
            'prix' => 100000,
            'quantite_stock' => 5,
            'type_livraison' => 'physique',
        ])->assertCreated();

        $this->assertSame(0, NotificationOrdispace::where('type_notification', 'produit_a_valider')->count());
    }

    public function test_ladmin_est_notifie_dune_nouvelle_reclamation(): void
    {
        $admin = $this->creerAdmin();
        $client = $this->creerClient();

        $this->actingAs($client)->postJson('/api/v1/reclamations', [
            'sujet' => 'Colis en retard', 'description' => 'Toujours pas livré.',
        ])->assertCreated();

        $this->assertSame(1, NotificationOrdispace::where('user_id', $admin->id)->where('type_notification', 'reclamation')->count());
    }

    public function test_ladmin_est_notifie_dune_declaration_de_panne(): void
    {
        $admin = $this->creerAdmin();
        $client = $this->creerClient();

        $this->actingAs($client)->postJson('/api/v1/sav/demandes', [
            'description_probleme' => 'Écran noir au démarrage.',
        ])->assertCreated();

        $this->assertSame(1, NotificationOrdispace::where('user_id', $admin->id)->where('type_notification', 'panne_declaree')->count());
    }

    public function test_ladmin_est_notifie_dune_demande_de_retrait(): void
    {
        $admin = $this->creerAdmin();
        $coordinateur = $this->creerCoordinateur();
        $livreurUser = $this->creerLivreur();
        $produit = $this->creerProduitPhysique(['quantite_stock' => 10, 'commission_revente' => 15000]);

        // Crée de vraies ventes boutique pour ce livreur (donc une commission
        // disponible réelle) — même commande artisan que PortefeuilleCommissionsTest.
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('boutique:ventes-demo', [
            'email' => $livreurUser->email, '--produit' => $produit->id,
        ]));

        $reponse = $this->actingAs($livreurUser)->postJson('/api/v1/boutique/retraits', [
            'montant' => RETRAIT_MONTANT_MINIMUM,
            'operateur' => OPERATEURS_RETRAIT[0],
            'telephone' => '0700000000',
        ]);

        $reponse->assertCreated();
        $this->assertSame(1, NotificationOrdispace::where('user_id', $admin->id)->where('type_notification', 'retrait_demande')->count());
        $this->assertSame(0, NotificationOrdispace::where('user_id', $coordinateur->id)->where('type_notification', 'retrait_demande')->count());
    }
}
