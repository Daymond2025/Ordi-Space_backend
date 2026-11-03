<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Écran "Diagnostic technique" (app Maintenancier) : problèmes cochés dans
 * la liste (`problemes`), précision libre (`probleme_identifie`), cause de la
 * panne, niveau de gravité, temps estimé. La colonne texte `diagnostic` reste
 * alimentée (résumé) pour les écrans qui l'affichent déjà.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->json('problemes')->nullable()->after('diagnostic');
            $table->text('probleme_identifie')->nullable()->after('problemes');
            $table->text('cause_panne')->nullable()->after('probleme_identifie');
            $table->enum('niveau_gravite', ['faible', 'moyenne', 'critique'])->nullable()->after('cause_panne');
            $table->unsignedSmallInteger('duree_estimee_minutes')->nullable()->after('niveau_gravite');
        });
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn(['problemes', 'probleme_identifie', 'cause_panne', 'niveau_gravite', 'duree_estimee_minutes']);
        });
    }
};
