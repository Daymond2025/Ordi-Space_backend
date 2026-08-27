<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Produit "boosté" (Espace Coordinateur, Catalogue) : sélectionné avec
     * le fournisseur pour une commercialisation plus intensive.
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->boolean('est_booste')->default(false)->after('statut_produit');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('est_booste');
        });
    }
};
