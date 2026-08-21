<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livraisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->unique()->constrained('commandes')->cascadeOnDelete();
            // Nullable : la livraison est créée dès la préparation, le livreur
            // n'est affecté qu'au moment de la prise en charge (répartition/dispatch).
            $table->foreignId('livreur_id')->nullable()->constrained('livreurs', 'user_id')->nullOnDelete();
            $table->foreignId('adresse_id')->constrained('adresses')->restrictOnDelete();
            $table->timestamp('date_prise_en_charge')->nullable();
            $table->timestamp('date_livraison_prevue')->nullable();
            $table->timestamp('date_livraison_effective')->nullable();
            $table->enum('statut_livraison', [
                'en_preparation', 'en_attente_livreur', 'en_cours', 'livree', 'echouee',
            ])->default('en_preparation')->index();
            $table->string('preuve_livraison')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livraisons');
    }
};
