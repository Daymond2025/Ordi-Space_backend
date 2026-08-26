<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un compte Client créé via la connexion par téléphone (WhatsApp + OTP)
     * n'a plus d'e-mail — seul le personnel (admin, coordinateur, etc.) reste
     * authentifié par e-mail/mot de passe et continue d'en fournir un.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
