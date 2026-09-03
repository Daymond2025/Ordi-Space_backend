<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fiche technique (Espace Coordinateur, écran Détail produit) : le
     * catalogue ne contient que des ordinateurs, d'où des colonnes fixes
     * plutôt qu'un schéma libre. `cadeaux` est une liste de pastilles
     * (ex. "Souris", "Sacs") affichées sur la fiche produit.
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('processeur')->nullable()->after('duree_garantie_mois');
            $table->string('memoire_ram')->nullable()->after('processeur');
            $table->string('stockage')->nullable()->after('memoire_ram');
            $table->string('taille')->nullable()->after('stockage');
            $table->string('systeme_exploitation')->nullable()->after('taille');
            $table->string('carte_graphique')->nullable()->after('systeme_exploitation');
            $table->json('cadeaux')->nullable()->after('carte_graphique');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn([
                'processeur', 'memoire_ram', 'stockage', 'taille',
                'systeme_exploitation', 'carte_graphique', 'cadeaux',
            ]);
        });
    }
};
