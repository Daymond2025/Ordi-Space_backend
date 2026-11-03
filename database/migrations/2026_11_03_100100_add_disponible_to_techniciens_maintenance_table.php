<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bascule "En ligne" de l'accueil Maintenancier — même patron que
 * livreurs.disponible (voir MoiController::basculerDisponibilite()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('techniciens_maintenance', function (Blueprint $table) {
            $table->boolean('disponible')->default(true)->after('specialite');
        });
    }

    public function down(): void
    {
        Schema::table('techniciens_maintenance', function (Blueprint $table) {
            $table->dropColumn('disponible');
        });
    }
};
