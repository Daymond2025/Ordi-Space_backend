<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frais_livraison_produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            // restrictOnDelete : une localité est une référence stable, elle
            // ne doit pas pouvoir disparaître silencieusement sous un barème actif.
            $table->foreignId('localite_id')->constrained('localites')->restrictOnDelete();
            $table->decimal('montant', 10, 2);
            $table->timestamps();
            $table->unique(['produit_id', 'localite_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frais_livraison_produits');
    }
};
