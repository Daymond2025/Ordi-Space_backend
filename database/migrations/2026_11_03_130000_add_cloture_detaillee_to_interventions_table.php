<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Écran "Marquer terminé" (app Maintenancier) : prestations réalisées,
 * pièces remplacées, date/heure de fin, durée réelle et note sur l'état de
 * l'ordinateur. `reparation_effectuee` reste alimentée (résumé) pour les
 * écrans qui l'affichent déjà.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->json('prestations')->nullable()->after('reparation_effectuee');
            $table->json('pieces_remplacees')->nullable()->after('prestations');
            $table->dateTime('date_fin')->nullable()->after('pieces_remplacees');
            $table->unsignedSmallInteger('duree_reelle_minutes')->nullable()->after('date_fin');
            $table->text('note_fin')->nullable()->after('duree_reelle_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn(['prestations', 'pieces_remplacees', 'date_fin', 'duree_reelle_minutes', 'note_fin']);
        });
    }
};
