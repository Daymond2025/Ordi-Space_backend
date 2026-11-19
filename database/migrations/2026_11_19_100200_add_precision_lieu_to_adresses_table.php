<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Précision libre du lieu de livraison (quartier, repère…) saisie par le
 * commercial dans "Nouvelle commande" — affichée après la localité, voir
 * Adresse::libelleZone().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adresses', function (Blueprint $table) {
            $table->string('precision_lieu')->nullable()->after('rue');
        });
    }

    public function down(): void
    {
        Schema::table('adresses', function (Blueprint $table) {
            $table->dropColumn('precision_lieu');
        });
    }
};
