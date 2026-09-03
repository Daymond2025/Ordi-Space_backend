<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instantané immuable {prix_liste, prix_propose} publié une seule fois
     * au démarrage d'une négociation de prix — voir
     * MessageController::demarrerNegociationPrix().
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('type', ['texte', 'image', 'video', 'audio', 'note_vocale', 'document', 'rapport', 'commande_creee', 'proposition_prix'])->change();
        });
    }

    /**
     * Attention : échoue si des messages portent déjà le type
     * 'proposition_prix' — les supprimer avant de rollback.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('type', ['texte', 'image', 'video', 'audio', 'note_vocale', 'document', 'rapport', 'commande_creee'])->change();
        });
    }
};
