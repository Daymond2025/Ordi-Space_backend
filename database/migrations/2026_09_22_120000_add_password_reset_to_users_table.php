<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mot de passe oublié — mêmes conventions que le code de connexion à deux
 * facteurs (two_factor_*) : code à 6 chiffres hashé au repos, expiration,
 * compteur de tentatives contre le brute-force. Colonnes dédiées plutôt que
 * de réutiliser two_factor_code : une demande de réinitialisation ne doit
 * jamais invalider un code de connexion en cours, et inversement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_reset_code')->nullable()->after('two_factor_tentatives');
            $table->timestamp('password_reset_expires_at')->nullable()->after('password_reset_code');
            $table->unsignedTinyInteger('password_reset_tentatives')->default(0)->after('password_reset_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_reset_code', 'password_reset_expires_at', 'password_reset_tentatives']);
        });
    }
};
