<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ligne_commande_id')->unique()->constrained('lignes_commande')->cascadeOnDelete();
            $table->foreignId('fournisseur_id')->constrained('fournisseurs', 'user_id')->restrictOnDelete();
            $table->text('motif');
            $table->enum('statut_retour', ['en_attente', 'accepte', 'refuse', 'rembourse'])->default('en_attente');
            $table->timestamp('date_retour')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retours');
    }
};
