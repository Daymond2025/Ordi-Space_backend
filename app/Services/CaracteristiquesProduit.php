<?php

namespace App\Services;

/**
 * Les caractéristiques d'un produit sont saisies en texte libre ("16GB DDR4-2400",
 * "1 To SSD", "14\" Pouces"). Les filtres du catalogue ("RAM 8 Go", "stockage 500 Go
 * et +", "14 pouces") ont besoin de nombres : ces fonctions les en extraient, une
 * seule fois, à l'enregistrement du produit (voir Produit::booted()).
 */
class CaracteristiquesProduit
{
    /** "16GB DDR4-2400" → 16 ; "512 Go SSD" → 512 ; "1 To" / "1TB" → 1000 ; sans chiffre → null. */
    public static function capaciteEnGo(?string $texte): ?int
    {
        if ($texte === null || ! preg_match('/(\d+(?:[.,]\d+)?)\s*(to|tb|go|gb|g|t)?\b/iu', $texte, $m)) {
            return null;
        }

        $valeur = (float) str_replace(',', '.', $m[1]);
        $unite = strtolower($m[2] ?? '');

        return (int) round(in_array($unite, ['to', 'tb', 't'], true) ? $valeur * 1000 : $valeur);
    }

    /** "14\" Pouces" → 14.0 ; "15,6 pouces" → 15.6 ; sans chiffre → null. */
    public static function taillePouces(?string $texte): ?float
    {
        if ($texte === null || ! preg_match('/(\d+(?:[.,]\d+)?)/', $texte, $m)) {
            return null;
        }

        return round((float) str_replace(',', '.', $m[1]), 1);
    }

    /**
     * Marque telle que stockée : majuscules pour celles du filtre ("hp" → "HP",
     * "Apple" → "MACBOOK"), saisie libre conservée pour les autres ("Acer"),
     * "Autre"/vide → null (le filtre "Autre" retrouve toutes les marques hors liste).
     */
    public static function normaliserMarque(?string $marque): ?string
    {
        $marque = trim((string) $marque);
        $majuscules = strtoupper($marque);

        return match (true) {
            $marque === '', $majuscules === 'AUTRE' => null,
            $majuscules === 'APPLE' => 'MACBOOK',
            in_array($majuscules, MARQUES_ORDINATEUR, true) => $majuscules,
            default => $marque,
        };
    }

    /**
     * Marque déduite du nom du produit quand elle n'a pas été renseignée
     * ("HP 840 G5 Core i5" → "HP"). Uniquement les marques du filtre : au-delà,
     * mieux vaut laisser vide que de deviner.
     */
    public static function marqueDepuisNom(?string $nom): ?string
    {
        if ($nom === null) {
            return null;
        }

        foreach (MARQUES_ORDINATEUR as $marque) {
            $motif = $marque === 'MACBOOK' ? '(?:macbook|apple)' : preg_quote($marque, '/');
            if (preg_match('/\b'.$motif.'\b/iu', $nom)) {
                return $marque;
            }
        }

        return null;
    }
}
