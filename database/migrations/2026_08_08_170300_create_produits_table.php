<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fournisseur_id')->constrained('fournisseurs', 'user_id')->cascadeOnDelete();
            $table->foreignId('categorie_id')->constrained('categories')->restrictOnDelete();
            $table->string('nom_produit');
            $table->text('description')->nullable();
            $table->decimal('prix', 12, 2);
            $table->unsignedInteger('quantite_stock')->default(0);
            $table->enum('statut_produit', ['en_attente', 'valide', 'rejete', 'corrige'])->default('en_attente')->index();
            $table->timestamp('date_ajout')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
