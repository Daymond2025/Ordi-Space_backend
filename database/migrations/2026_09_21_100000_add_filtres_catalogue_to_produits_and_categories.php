<?php

use App\Services\CaracteristiquesProduit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Écran "Catégorie" de la Boutique (Livreur) : filtres par marque, RAM,
 * stockage, taille… et tuiles d'accessoires/logiciels.
 *
 * - produits : la marque, et les valeurs numériques extraites des caractéristiques
 *   saisies en texte libre (voir CaracteristiquesProduit) pour pouvoir filtrer en SQL ;
 * - catégories : la famille (onglet du filtre), le groupe d'affichage des logiciels
 *   ("Pack office", "Navigateur"…), un libellé court pour la tuile et son ordre —
 *   `ordre_filtre` non nul = la catégorie est proposée comme choix dans les filtres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('marque', 60)->nullable()->after('nom_produit');
            $table->unsignedInteger('ram_go')->nullable()->after('memoire_ram');
            $table->unsignedInteger('stockage_go')->nullable()->after('stockage');
            $table->decimal('taille_pouces', 4, 1)->nullable()->after('taille');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('famille', 30)->nullable()->after('nom_categorie')->index();
            $table->string('groupe', 100)->nullable()->after('famille');
            $table->string('libelle', 100)->nullable()->after('groupe');
            $table->unsignedSmallInteger('ordre_filtre')->nullable()->after('libelle');
        });

        // Produits déjà en base : on dérive les nouvelles colonnes de ce qu'ils contiennent.
        DB::table('produits')->orderBy('id')->each(function ($produit) {
            DB::table('produits')->where('id', $produit->id)->update([
                'marque' => CaracteristiquesProduit::marqueDepuisNom($produit->nom_produit),
                'ram_go' => CaracteristiquesProduit::capaciteEnGo($produit->memoire_ram),
                'stockage_go' => CaracteristiquesProduit::capaciteEnGo($produit->stockage),
                'taille_pouces' => CaracteristiquesProduit::taillePouces($produit->taille),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['famille']);
            $table->dropColumn(['famille', 'groupe', 'libelle', 'ordre_filtre']);
        });

        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn(['marque', 'ram_go', 'stockage_go', 'taille_pouces']);
        });
    }
};
