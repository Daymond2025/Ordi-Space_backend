<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discussion façon WhatsApp — Espace Coordinateur (Phase 2). Une seule
     * table pour les deux contextes (produit ET commande, exactement l'un
     * des deux FK renseigné, vérifié en validation — pas de contrainte
     * polymorphique, aucun précédent morphTo dans ce code).
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->cascadeOnDelete();
            $table->foreignId('commande_id')->nullable()->constrained('commandes')->cascadeOnDelete();
            $table->foreignId('auteur_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['texte', 'image', 'video', 'audio', 'note_vocale', 'document']);
            $table->text('contenu')->nullable();
            $table->string('fichier')->nullable();
            $table->timestamp('date_envoi')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
