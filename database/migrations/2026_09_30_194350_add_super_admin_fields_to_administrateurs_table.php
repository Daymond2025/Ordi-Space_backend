<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administrateurs', function (Blueprint $table) {
            // Défaut à true : tous les admins déjà existants (y compris
            // l'admin racine) gardent un accès total, aucune régression. Un
            // nouvel admin restreint est créé explicitement avec false par
            // AdministrateurController::store().
            $table->boolean('est_super_admin')->default(true)->after('user_id');
            // Liste des espaces (sections de l'admin web) autorisés pour un
            // admin NON super-admin — ignoré si est_super_admin = true.
            $table->json('espaces_autorises')->nullable()->after('est_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('administrateurs', function (Blueprint $table) {
            $table->dropColumn(['est_super_admin', 'espaces_autorises']);
        });
    }
};
