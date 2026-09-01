<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dernière consultation de la conversation d'une commande par un
     * utilisateur — même principe que consultations_produits, mais pour le
     * badge "non lu" par commande de l'écran Discussion produit.
     */
    public function up(): void
    {
        Schema::create('consultations_commandes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $table->timestamp('consulte_le');
            $table->unique(['user_id', 'commande_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations_commandes');
    }
};
