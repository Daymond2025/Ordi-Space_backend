<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rendez_vous', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_sav_id')->constrained('demandes_sav')->cascadeOnDelete();
            // Nullable : un rendez-vous peut être créé avant l'affectation d'un technicien.
            $table->foreignId('technicien_id')->nullable()->constrained('techniciens_maintenance', 'user_id')->nullOnDelete();
            $table->dateTime('date_rdv');
            $table->string('lieu')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rendez_vous');
    }
};
