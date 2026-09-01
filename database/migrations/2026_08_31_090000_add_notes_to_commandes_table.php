<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Informations complémentaires" du flux de création par copier-coller
     * (Espace Coordinateur) — distinct de `bonus_offerts` (sémantique différente).
     */
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->string('notes', 500)->nullable()->after('bonus_offerts');
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
