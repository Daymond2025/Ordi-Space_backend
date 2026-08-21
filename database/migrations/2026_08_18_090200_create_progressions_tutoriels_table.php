<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suivi individuel des tutoriels/formations (Academy Space) : jusqu'ici
     * Tutoriel était un contenu global sans lien avec les clients qui le
     * consultent — impossible de savoir qui a vu/terminé quoi.
     */
    public function up(): void
    {
        Schema::create('progressions_tutoriels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients', 'user_id')->cascadeOnDelete();
            $table->foreignId('tutoriel_id')->constrained('tutoriels')->cascadeOnDelete();
            $table->enum('statut', ['vu', 'termine'])->default('vu');
            $table->timestamp('date_vue')->useCurrent();
            $table->timestamps();
            $table->unique(['client_id', 'tutoriel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progressions_tutoriels');
    }
};
