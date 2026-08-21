<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_commande', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->unsignedInteger('quantite');
            $table->decimal('prix_unitaire', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_commande');
    }
};
