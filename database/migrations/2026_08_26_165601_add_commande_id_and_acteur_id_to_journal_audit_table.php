<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Onglet "Suivi" (Espace Coordinateur, Phase 2) : journal_audit.user_id
     * désigne le client concerné (utilisé par la fiche client admin), pas
     * l'acteur — deux colonnes nullables distinctes sont donc nécessaires
     * pour une timeline fiable par commande, avec l'identité de l'acteur.
     * Les commandes créées avant cette migration auront un Suivi vide/
     * partiel (commande_id n'existait pas encore) — attendu, pas un bug.
     */
    public function up(): void
    {
        Schema::table('journal_audit', function (Blueprint $table) {
            $table->foreignId('commande_id')->nullable()->after('user_id')->constrained('commandes')->nullOnDelete();
            $table->foreignId('acteur_id')->nullable()->after('commande_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_audit', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commande_id');
            $table->dropConstrainedForeignId('acteur_id');
        });
    }
};
