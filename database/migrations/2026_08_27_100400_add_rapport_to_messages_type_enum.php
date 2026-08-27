<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rapport quotidien automatique publié dans la discussion produit — voir
     * MessageController::genererRapportQuotidienSiNecessaire().
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('type', ['texte', 'image', 'video', 'audio', 'note_vocale', 'document', 'rapport'])->change();
        });
    }

    /**
     * Attention : échoue si des messages portent déjà le type 'rapport' —
     * les supprimer avant de rollback.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('type', ['texte', 'image', 'video', 'audio', 'note_vocale', 'document'])->change();
        });
    }
};
