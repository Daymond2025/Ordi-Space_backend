<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Écran "Livreurs" (Espace Coordinateur) — statut lecture seule pour
     * cette passe (pas de bouton pour le modifier, sera réglé plus tard par
     * le livreur lui-même ou un futur bouton coordinateur).
     */
    public function up(): void
    {
        Schema::table('livreurs', function (Blueprint $table) {
            $table->boolean('disponible')->default(true)->after('zone_couverture');
        });
    }

    public function down(): void
    {
        Schema::table('livreurs', function (Blueprint $table) {
            $table->dropColumn('disponible');
        });
    }
};
