<?php

namespace App\Services;

use App\Models\Categorie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Filtres de l'écran "Catégorie" de la Boutique (Livreur), appliqués à
 * GET /produits. Tous les paramètres sont facultatifs et se cumulent (ET) ;
 * à l'intérieur d'un même paramètre à plusieurs valeurs, c'est un OU
 * ("HP ou Dell", "Core i5 ou Core i7").
 *
 *   famille=ordinateur|accessoires|logiciels
 *   categories[]=id            types d'ordinateur, tuiles accessoires/logiciels
 *   marques[]=hp|dell|…|autre  "autre" = toute marque hors liste, ou non renseignée
 *   processeurs[]=Core i5
 *   stockage_min=500           Go — "500 Go et +" ; stockage_type=ssd|hdd
 *   rams[]=8   ram_min=16      Go — valeurs exactes, et/ou "16 Go et +"
 *   tailles[]=14               pouces — 14 couvre 14 à 14,9
 *   revente=1                  seulement les produits ouverts à la revente
 */
class FiltresCatalogue
{
    public static function appliquer(Builder $query, Request $request): void
    {
        if ($request->filled('famille')) {
            $query->whereIn('categorie_id', Categorie::where('famille', $request->string('famille'))->pluck('id'));
        }

        if ($ids = self::liste($request, 'categories')) {
            $query->whereIn('categorie_id', array_map('intval', $ids));
        }

        if ($request->boolean('revente')) {
            $query->whereNotNull('commission_revente');
        }

        if ($marques = self::liste($request, 'marques')) {
            $marques = array_map('mb_strtolower', $marques);
            $connues = array_map('mb_strtolower', MARQUES_ORDINATEUR);
            $nommees = array_values(array_diff($marques, ['autre']));

            $query->where(function (Builder $q) use ($marques, $nommees, $connues) {
                if ($nommees) {
                    $q->whereIn(DB::raw('LOWER(marque)'), $nommees);
                }
                if (in_array('autre', $marques, true)) {
                    $q->orWhereNull('marque')->orWhereNotIn(DB::raw('LOWER(marque)'), $connues);
                }
            });
        }

        if ($processeurs = self::liste($request, 'processeurs')) {
            $query->where(function (Builder $q) use ($processeurs) {
                foreach ($processeurs as $processeur) {
                    // "Core i5" → %Core%i5% : insensible aux espaces/tirets ("Core-i5", "Core  i5").
                    $q->orWhere('processeur', 'LIKE', '%'.preg_replace('/[\s-]+/', '%', trim($processeur)).'%');
                }
            });
        }

        if ($request->filled('stockage_min')) {
            $query->where('stockage_go', '>=', $request->integer('stockage_min'));
        }

        if (in_array($type = strtolower($request->string('stockage_type')->toString()), ['ssd', 'hdd'], true)) {
            $query->where('stockage', 'LIKE', '%'.$type.'%');
        }

        $rams = array_map('intval', self::liste($request, 'rams'));
        if ($rams || $request->filled('ram_min')) {
            $query->where(function (Builder $q) use ($rams, $request) {
                if ($rams) {
                    $q->whereIn('ram_go', $rams);
                }
                if ($request->filled('ram_min')) {
                    $q->orWhere('ram_go', '>=', $request->integer('ram_min'));
                }
            });
        }

        if ($tailles = self::liste($request, 'tailles')) {
            $query->where(function (Builder $q) use ($tailles) {
                foreach ($tailles as $taille) {
                    $q->orWhereBetween('taille_pouces', [(float) $taille, (float) $taille + 0.9]);
                }
            });
        }
    }

    /** Valeurs non vides d'un paramètre tableau (`?x[]=a&x[]=b`) ou séparé par des virgules (`?x=a,b`). */
    private static function liste(Request $request, string $cle): array
    {
        $valeur = $request->input($cle);
        $valeurs = is_array($valeur) ? $valeur : explode(',', (string) $valeur);

        return array_values(array_filter(array_map('trim', array_map('strval', $valeurs)), fn ($v) => $v !== ''));
    }
}
