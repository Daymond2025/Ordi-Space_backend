<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Charge utile structurée des messages système (instantané de commande à
     * la création, compteurs du rapport quotidien) — les messages "plats"
     * (texte/image/...) continuent d'utiliser uniquement `contenu`/`fichier`.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->json('donnees')->nullable()->after('fichier');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('donnees');
        });
    }
};
