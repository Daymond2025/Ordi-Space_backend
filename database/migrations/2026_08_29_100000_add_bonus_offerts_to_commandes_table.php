<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Texte libre saisi à la création de la commande — affiché en pastilles
     * dans la carte "Nouvelle commande" de la discussion produit.
     */
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->string('bonus_offerts')->nullable()->after('frais_livraison');
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('bonus_offerts');
        });
    }
};
