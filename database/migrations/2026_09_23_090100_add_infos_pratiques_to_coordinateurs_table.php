<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fiche "partenaire" du coordinateur telle que la voit un livreur sur
     * "Mes infos" : où le trouver, quand, et sur quelle zone il intervient
     * (même idée que fournisseurs.zone_couverte / livreurs.zone_couverture).
     */
    public function up(): void
    {
        Schema::table('coordinateurs', function (Blueprint $table) {
            $table->string('adresse')->nullable();
            $table->string('horaires')->nullable();
            $table->string('zone_couverte')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coordinateurs', function (Blueprint $table) {
            $table->dropColumn(['adresse', 'horaires', 'zone_couverte']);
        });
    }
};
