<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            // Horodatage dédié de la validation coordinateur — nécessaire pour
            // afficher un suivi de commande fiable côté client (chaque étape
            // doit reposer sur une date réellement persistée, jamais déduite).
            $table->timestamp('date_validation')->nullable()->after('date_commande');
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('date_validation');
        });
    }
};
