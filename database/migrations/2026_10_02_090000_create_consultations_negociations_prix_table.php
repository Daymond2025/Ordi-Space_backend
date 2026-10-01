<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dernière consultation du fil "Négociation de prix" d'un produit par un
     * utilisateur — même principe que consultations_produits, mais une table
     * à part : la discussion produit générale (est_negociation_prix=false)
     * et la négociation (est_negociation_prix=true) sont deux fils distincts,
     * donc deux pointeurs de lecture distincts (consulter l'un ne doit pas
     * marquer l'autre comme lu). Sert le badge "nouveau message de
     * négociation" côté fournisseur (voir ProduitController::show()).
     */
    public function up(): void
    {
        Schema::create('consultations_negociations_prix', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->timestamp('consulte_le');
            $table->unique(['user_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations_negociations_prix');
    }
};
