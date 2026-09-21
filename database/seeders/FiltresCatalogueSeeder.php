<?php

namespace Database\Seeders;

use App\Models\Categorie;
use Illuminate\Database\Seeder;

/**
 * Catégories proposées comme choix dans l'écran "Catégorie" de la Boutique
 * (Livreur) : types d'ordinateur, tuiles d'accessoires et de logiciels.
 * Idempotent — relançable sans doublon, et les catégories déjà existantes
 * (Ordinateurs portables, Chargeur, Souris…) sont rattachées, pas recréées.
 * L'Admin peut ensuite en ajouter ou les réordonner.
 */
class FiltresCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        // Familles "racine" : regroupent leurs produits mais ne sont pas des choix du filtre.
        foreach (['Accessoires' => 'accessoires', 'Logiciels' => 'logiciels'] as $nom => $famille) {
            $this->categorie($nom, $famille);
        }

        $this->categorie('Ordinateurs portables', 'ordinateur', libelle: 'Ordinateur portable', ordre: 1);
        $this->categorie('Ordinateur bureau', 'ordinateur', libelle: 'Ordinateur bureau', ordre: 2);

        $this->categorie('Chargeur', 'accessoires', libelle: 'Chargeurs', ordre: 1);
        $this->categorie('Souris', 'accessoires', libelle: 'Souris', ordre: 2);
        $this->categorie('Batteries', 'accessoires', libelle: 'Batteries', ordre: 3);
        $this->categorie('Sacs pc', 'accessoires', libelle: 'Sacs PC', ordre: 4);

        $ordre = 1;
        foreach ([
            'Pack office' => ['Word', 'Excel', 'Power Point', 'Publisher', 'Autre pack office' => 'Autre'],
            'Navigateur' => ['Chrome', 'Opera', 'Firefox', 'Autre navigateur' => 'Autre'],
            'Spécial suite Adobe' => ['Photoshop', 'Illustrator', 'Premiere Pro', 'After Effects'],
        ] as $groupe => $logiciels) {
            foreach ($logiciels as $cle => $valeur) {
                // Tableau mixte : "Nom" seul, ou "Nom en base" => "libellé affiché".
                [$nom, $libelle] = is_int($cle) ? [$valeur, $valeur] : [$cle, $valeur];
                $this->categorie($nom, 'logiciels', $groupe, $libelle, $ordre++);
            }
        }
    }

    private function categorie(string $nom, string $famille, ?string $groupe = null, ?string $libelle = null, ?int $ordre = null): void
    {
        Categorie::updateOrCreate(
            ['nom_categorie' => $nom],
            ['famille' => $famille, 'groupe' => $groupe, 'libelle' => $libelle, 'ordre_filtre' => $ordre],
        );
    }
}
