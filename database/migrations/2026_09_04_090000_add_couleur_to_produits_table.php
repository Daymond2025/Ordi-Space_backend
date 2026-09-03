<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sélection unique (pas une liste comme `cadeaux`) — écran "Ajout d'un
     * produit" (Espace Coordinateur), étape caractéristiques/couleurs.
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('couleur')->nullable()->after('carte_graphique');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('couleur');
        });
    }
};
