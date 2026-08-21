<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reclamations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients', 'user_id')->restrictOnDelete();
            // Nullable : une réclamation ne concerne pas toujours une commande précise.
            $table->foreignId('commande_id')->nullable()->constrained('commandes')->nullOnDelete();
            $table->string('sujet');
            $table->text('description');
            $table->enum('statut', ['nouvelle', 'en_cours', 'resolue', 'rejetee'])->default('nouvelle')->index();
            $table->text('reponse_admin')->nullable();
            $table->timestamp('date_reclamation')->useCurrent();
            $table->timestamp('date_traitement')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reclamations');
    }
};
