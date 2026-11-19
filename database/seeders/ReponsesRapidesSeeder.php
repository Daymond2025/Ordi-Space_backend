<?php

namespace Database\Seeders;

use App\Models\ReponseRapide;
use Illuminate\Database\Seeder;

/**
 * Bibliothèque de réponses rapides de l'app Commercial — les mêmes qu'en
 * local, pour qu'elles existent aussi en production. Idempotent : une
 * réponse déjà présente (même titre) n'est ni dupliquée ni écrasée, l'Admin
 * peut donc la modifier ensuite depuis son espace.
 */
class ReponsesRapidesSeeder extends Seeder
{
    private const REPONSES = [
        [
            'titre' => 'Objection à une demande de réduction',
            'contenu' => 'Nous comprenons votre souhait de réduction, mais notre prix reflète déjà la meilleure qualité au meilleur tarif du marché pour ce modèle.',
            'est_favori' => true,
        ],
        [
            'titre' => 'Relance après silence du client',
            'contenu' => 'Bonjour, je reviens vers vous concernant votre commande en cours, souhaitez-vous que je vous aide à finaliser la livraison ?',
            'est_favori' => true,
        ],
        [
            'titre' => 'Présentation garantie produit',
            'contenu' => "Tous nos ordinateurs sont couverts par une garantie de 12 mois, pièces et main d'oeuvre incluses, directement avec notre service après-vente.",
            'est_favori' => false,
        ],
        [
            'titre' => 'Délai de livraison Abidjan',
            'contenu' => 'La livraison à Abidjan se fait généralement en moins de 24h après validation de votre commande, selon votre commune.',
            'est_favori' => false,
        ],
    ];

    public function run(): void
    {
        foreach (self::REPONSES as $reponse) {
            ReponseRapide::firstOrCreate(
                ['titre' => $reponse['titre']],
                ['contenu' => $reponse['contenu'], 'est_favori' => $reponse['est_favori'], 'nombre_copies' => 0]
            );
        }
    }
}
