<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Permettre au fournisseur d'épingler, ou retirer de la liste une carte
     * produit commande" (retour de test réel, fil "activité récente" de
     * l'accueil) — préférence par (utilisateur, produit) :
     * - `epingle` : la carte reste toujours en tête de liste, peu importe son
     *   activité (voir MessageController::produitsActifs()).
     * - `masque_depuis` : la carte est masquée tant qu'aucune activité plus
     *   récente que ce moment n'est survenue (réapparaît automatiquement à la
     *   prochaine commande/message, comme une archive — jamais de suppression
     *   définitive).
     */
    public function up(): void
    {
        Schema::create('preferences_accueil_produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->boolean('epingle')->default(false);
            $table->timestamp('masque_depuis')->nullable();
            $table->unique(['user_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preferences_accueil_produits');
    }
};
