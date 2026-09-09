<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preuves_reclamation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reclamation_id')->constrained('reclamations')->cascadeOnDelete();
            $table->string('fichier');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preuves_reclamation');
    }
};
