<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bibliothèque de réponses rapides (écran "Réponse rapide", app Commercial)
 * — contenu entièrement géré par l'Admin (demande explicite : "les réponses
 * rapides seront ajoutées par l'admin dans l'espace commercial"), jamais
 * créé par le commercial lui-même. `est_favori` : mis en avant par l'Admin
 * (pas un favori personnel par commercial — aucune UI de bascule par
 * utilisateur dans la maquette, juste l'onglet "Favoris"). `nombre_copies` :
 * compteur global, incrémenté à chaque "Copier" (onglet "Plus utilisés").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reponses_rapides', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->text('contenu');
            $table->boolean('est_favori')->default(false);
            $table->unsignedInteger('nombre_copies')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reponses_rapides');
    }
};
