<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutoriels', function (Blueprint $table) {
            // Distingue les deux onglets d'Academy Space côté client :
            // "Tutos rapide" vs "Formation informatique".
            $table->enum('type', ['tutoriel_rapide', 'formation'])
                ->default('tutoriel_rapide')
                ->after('titre');
        });
    }

    public function down(): void
    {
        Schema::table('tutoriels', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
