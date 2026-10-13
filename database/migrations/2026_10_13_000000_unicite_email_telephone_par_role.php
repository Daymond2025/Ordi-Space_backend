<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une même personne peut avoir un compte sur plusieurs rôles (ex.
     * Livreur et Commercial) avec le même email/téléphone — c'est le rôle
     * qui distingue les comptes désormais, pas l'email/téléphone seul.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->dropUnique('users_telephone_unique');
            $table->unique(['email', 'type_utilisateur']);
            $table->unique(['telephone', 'type_utilisateur']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email', 'type_utilisateur']);
            $table->dropUnique(['telephone', 'type_utilisateur']);
            $table->unique('email');
            $table->unique('telephone');
        });
    }
};
