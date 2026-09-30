<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclamations', function (Blueprint $table) {
            $table->enum('statut', ['nouvelle', 'en_cours', 'resolue', 'rejetee', 'annulee'])
                ->default('nouvelle')->change();
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('reclamations')->where('statut', 'annulee')->update(['statut' => 'rejetee']);

        Schema::table('reclamations', function (Blueprint $table) {
            $table->enum('statut', ['nouvelle', 'en_cours', 'resolue', 'rejetee'])
                ->default('nouvelle')->change();
        });
    }
};
