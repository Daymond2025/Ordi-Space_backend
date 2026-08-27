<?php

namespace Database\Seeders;

use App\Models\Localite;
use Illuminate\Database\Seeder;

class LocaliteSeeder extends Seeder
{
    /**
     * Liste de référence standard (pas de source "officielle" à attendre —
     * ce sont de simples lignes de table, faciles à étendre plus tard).
     */
    private const COMMUNES_ABIDJAN = [
        'Abobo', 'Adjamé', 'Attécoubé', 'Cocody', 'Koumassi', 'Marcory', 'Plateau',
        'Port-Bouët', 'Treichville', 'Yopougon', 'Bingerville', 'Songon', 'Anyama',
    ];

    private const VILLES_COTE_IVOIRE = [
        'Bouaké', 'Yamoussoukro', 'San-Pédro', 'Korhogo', 'Man', 'Divo', 'Gagnoa',
        'Abengourou', 'Bondoukou', 'Séguéla', 'Odienné', 'Daloa', 'Bouaflé', 'Aboisso',
        'Agboville', 'Dabou', 'Grand-Bassam', 'Soubré', 'Issia', 'Ferkessédougou',
    ];

    public function run(): void
    {
        foreach (self::COMMUNES_ABIDJAN as $nom) {
            Localite::firstOrCreate(['nom' => $nom], ['type' => TYPE_LOCALITE_COMMUNE_ABIDJAN]);
        }

        foreach (self::VILLES_COTE_IVOIRE as $nom) {
            Localite::firstOrCreate(['nom' => $nom], ['type' => TYPE_LOCALITE_VILLE]);
        }
    }
}
