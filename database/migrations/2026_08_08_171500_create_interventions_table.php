<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rendez_vous_id')->unique()->constrained('rendez_vous')->cascadeOnDelete();
            $table->foreignId('technicien_id')->constrained('techniciens_maintenance', 'user_id')->restrictOnDelete();
            $table->text('diagnostic')->nullable();
            $table->text('reparation_effectuee')->nullable();
            $table->enum('statut_intervention', ['planifiee', 'en_cours', 'terminee', 'annulee'])->default('planifiee');
            $table->decimal('cout', 12, 2)->nullable();
            $table->timestamp('date_intervention')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interventions');
    }
};
