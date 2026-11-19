<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nouveau type de contenu : "formation_vente" (formations spécifiques de
 * vente, créées par l'Admin pour les seuls commerciaux). La colonne passe
 * d'un enum à une chaîne ; les valeurs acceptées sont contrôlées par
 * TutorielController::validerDonnees().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutoriels', function (Blueprint $table) {
            $table->string('type', 30)->default('tutoriel_rapide')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tutoriels', function (Blueprint $table) {
            $table->enum('type', ['tutoriel_rapide', 'formation'])->default('tutoriel_rapide')->change();
        });
    }
};
