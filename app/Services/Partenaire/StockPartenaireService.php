<?php

namespace App\Services\Partenaire;

use App\Models\MouvementStockPartenaire;
use App\Models\StockPartenaire;
use Illuminate\Validation\ValidationException;

/**
 * Seul point d'écriture du stock partenaire : verrouille la ligne de stock,
 * refuse tout passage sous zéro et journalise chaque mouvement. À appeler
 * dans une transaction (le verrou tient jusqu'au commit).
 */
class StockPartenaireService
{
    /**
     * @param  array{approvisionnement_id?: int, commande_partenaire_id?: int, motif?: string|null, auteur_id?: int|null}  $contexte
     */
    public static function mouvement(int $partenaireId, int $produitId, int $delta, string $type, array $contexte = []): StockPartenaire
    {
        StockPartenaire::firstOrCreate(['partenaire_id' => $partenaireId, 'produit_id' => $produitId], ['quantite' => 0]);

        $stock = StockPartenaire::where('partenaire_id', $partenaireId)->where('produit_id', $produitId)->lockForUpdate()->firstOrFail();
        $nouvelleQuantite = $stock->quantite + $delta;

        if ($nouvelleQuantite < 0) {
            throw ValidationException::withMessages([
                'stock' => ["Stock insuffisant pour le produit #{$produitId} : {$stock->quantite} disponible(s)."],
            ]);
        }

        $stock->update(['quantite' => $nouvelleQuantite]);

        MouvementStockPartenaire::create([
            'partenaire_id' => $partenaireId,
            'produit_id' => $produitId,
            'type' => $type,
            'quantite' => $delta,
            'stock_apres' => $nouvelleQuantite,
            ...$contexte,
        ]);

        return $stock;
    }

    /**
     * true si le partenaire a assez de stock pour toutes les lignes
     * (quantités cumulées par produit), lecture verrouillée.
     *
     * @param  iterable<array{produit_id: int, quantite: int}>|iterable<object>  $lignes
     */
    public static function suffisant(int $partenaireId, iterable $lignes): bool
    {
        $besoins = [];
        foreach ($lignes as $ligne) {
            $ligne = (array) (is_object($ligne) && method_exists($ligne, 'toArray') ? $ligne->toArray() : $ligne);
            $besoins[$ligne['produit_id']] = ($besoins[$ligne['produit_id']] ?? 0) + (int) $ligne['quantite'];
        }

        $disponibles = StockPartenaire::where('partenaire_id', $partenaireId)
            ->whereIn('produit_id', array_keys($besoins))
            ->lockForUpdate()
            ->pluck('quantite', 'produit_id');

        foreach ($besoins as $produitId => $quantite) {
            if ((int) ($disponibles[$produitId] ?? 0) < $quantite) {
                return false;
            }
        }

        return true;
    }
}
