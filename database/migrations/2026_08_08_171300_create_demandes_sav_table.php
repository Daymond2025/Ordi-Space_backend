<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_sav', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients', 'user_id')->restrictOnDelete();
            // Nullable : une demande peut être hors garantie.
            $table->foreignId('garantie_id')->nullable()->constrained('garanties')->nullOnDelete();
            $table->text('description_probleme');
            $table->enum('statut_demande', [
                'en_attente', 'planifiee', 'en_cours', 'resolue', 'cloturee',
            ])->default('en_attente')->index();
            $table->timestamp('date_demande')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_sav');
    }
};
