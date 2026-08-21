<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classe-association VALIDATION_PRODUIT : historise chaque cycle de
     * soumission/validation d'un produit par un coordinateur (un produit
     * rejeté peut être corrigé et resoumis, donc plusieurs lignes possibles).
     */
    public function up(): void
    {
        Schema::create('validations_produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->foreignId('coordinateur_id')->constrained('coordinateurs', 'user_id')->restrictOnDelete();
            $table->enum('decision', ['valide', 'rejete']);
            $table->text('motif_rejet')->nullable();
            $table->timestamp('date_validation')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validations_produits');
    }
};
