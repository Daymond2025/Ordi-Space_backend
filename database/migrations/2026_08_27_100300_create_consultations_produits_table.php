<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dernière consultation de la discussion d'un produit par un utilisateur
     * — permet un vrai badge "non lu" (nouvelles_activites) sur le fil
     * d'activité récente de l'accueil, à la manière de WhatsApp.
     */
    public function up(): void
    {
        Schema::create('consultations_produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->timestamp('consulte_le');
            $table->unique(['user_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations_produits');
    }
};
