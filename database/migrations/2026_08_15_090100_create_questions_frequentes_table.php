<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions_frequentes', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('reponse')->nullable();
            $table->string('fichier_audio')->nullable();
            $table->enum('statut', ['brouillon', 'publie'])->default('brouillon');
            $table->unsignedInteger('ordre_affichage')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions_frequentes');
    }
};
