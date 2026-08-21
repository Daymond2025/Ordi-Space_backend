<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Panier persisté côté serveur : jusqu'ici le "panier" n'existait que
     * localement dans l'app mobile jusqu'au passage en commande — un client
     * qui changeait d'appareil ou fermait l'app perdait son panier, et
     * l'admin n'avait aucune visibilité sur les paniers en cours.
     */
    public function up(): void
    {
        Schema::create('paniers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained('clients', 'user_id')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('lignes_panier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panier_id')->constrained('paniers')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->unsignedInteger('quantite')->default(1);
            $table->timestamps();
            $table->unique(['panier_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_panier');
        Schema::dropIfExists('paniers');
    }
};
