<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instantané immuable de la commande publié une seule fois à sa création
     * dans la discussion produit — voir CommandeController::store().
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('type', ['texte', 'image', 'video', 'audio', 'note_vocale', 'document', 'rapport', 'commande_creee'])->change();
        });
    }

    /**
     * Attention : échoue si des messages portent déjà le type
     * 'commande_creee' — les supprimer avant de rollback.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('type', ['texte', 'image', 'video', 'audio', 'note_vocale', 'document', 'rapport'])->change();
        });
    }
};
