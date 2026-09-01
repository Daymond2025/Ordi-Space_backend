<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\Message;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteragitAvecApi;
use Tests\TestCase;

/**
 * La carte "Nouvelle commande" (mode WhatsApp, en-tête noir) publiée à la
 * création d'une commande — instantané immuable distinct de la pastille de
 * suivi de statut (voir ConversationProduitTest).
 */
class CommandeCreeeMessageTest extends TestCase
{
    use RefreshDatabase, InteragitAvecApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_creer_une_commande_publie_un_message_commande_creee_avec_l_instantane_complet(): void
    {
        $commercial = $this->creerCommercial();
        $client = $this->creerClient(['nom' => 'Cissé', 'prenom' => 'Idriss', 'telephone' => '0759028545']);
        $adresse = $this->creerAdresseAvecLocalite($client);
        $produit = $this->creerProduitPhysique(['prix' => 800000]);

        $reponse = $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'bonus_offerts' => '2 Écouteurs, 1 chargeur',
        ]);

        $reponse->assertCreated();
        $commande = Commande::findOrFail($reponse->json('data.id'));

        $this->assertSame('2 Écouteurs, 1 chargeur', $commande->bonus_offerts);

        $message = Message::where('commande_id', $commande->id)->where('type', TYPE_MESSAGE_COMMANDE_CREEE)->first();
        $this->assertNotNull($message);
        $this->assertSame($produit->id, $message->produit_id);
        $this->assertSame($commercial->id, $message->auteur_id);
        // Comparaison champ par champ (assertEquals, pas assertSame) : le
        // cast JSON perd la distinction int/float pour les nombres entiers.
        $this->assertEquals([
            'nom_produit' => $produit->nom_produit,
            'photo' => null,
            'prix_produit' => 800000,
            'nom_client' => 'Idriss Cissé',
            'telephone' => '0759028545',
            'zone_livraison' => 'Abidjan, Cocody',
            'date_livraison_prevue' => null,
            'frais_livraison' => (float) $commande->frais_livraison,
            'remise' => 0,
            'total' => $commande->montantNet(),
            'bonus_offerts' => '2 Écouteurs, 1 chargeur',
            'notes' => null,
        ], $message->donnees);
    }

    public function test_le_message_commande_creee_est_expose_dans_le_fil_de_conversation(): void
    {
        $coordinateur = $this->creerCoordinateur();
        $commercial = $this->creerCommercial();
        $client = $this->creerClient();
        $adresse = $this->creerAdresseAvecLocalite($client);
        $produit = $this->creerProduitPhysique();

        $this->actingAs($commercial)->postJson('/api/v1/commandes', [
            'client_id' => $client->id,
            'adresse_id' => $adresse->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ])->assertCreated();

        $reponse = $this->actingAs($coordinateur)->getJson("/api/v1/produits/{$produit->id}/conversation");
        $reponse->assertOk();

        $item = collect($reponse->json('data.items'))->firstWhere('donnee.type', TYPE_MESSAGE_COMMANDE_CREEE);
        $this->assertNotNull($item);
        $this->assertSame('message', $item['type']);
        $this->assertNotNull($item['donnee']['donnees']);
        $this->assertSame($produit->nom_produit, $item['donnee']['donnees']['nom_produit']);
    }
}
