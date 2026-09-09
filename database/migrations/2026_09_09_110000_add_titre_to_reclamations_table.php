<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclamations', function (Blueprint $table) {
            $table->string('titre')->nullable()->after('sujet');
        });

        // Les réclamations existantes (et les futures créées par un appelant
        // qui n'envoie pas encore ce champ, ex. Ordi'Space_App_Mobile) reprennent
        // le sujet comme titre par défaut.
        DB::table('reclamations')->whereNull('titre')->update(['titre' => DB::raw('sujet')]);
    }

    public function down(): void
    {
        Schema::table('reclamations', function (Blueprint $table) {
            $table->dropColumn('titre');
        });
    }
};
