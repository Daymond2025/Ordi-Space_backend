<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi du retour physique au dépôt quand une commande est annulée pendant
 * qu'un livreur a déjà le colis (statut_livraison passait alors à
 * 'echouee' sans que rien ne distingue ce cas d'un simple échec de
 * livraison) — voir Commande::appliquerChangementStatut().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('livraisons', function (Blueprint $table) {
            $table->boolean('retour_necessaire')->default(false)->after('statut_livraison');
            $table->enum('statut_retour', ['en_cours', 'effectue'])->nullable()->after('retour_necessaire');
            $table->timestamp('date_retour_effectue')->nullable()->after('statut_retour');
        });
    }

    public function down(): void
    {
        Schema::table('livraisons', function (Blueprint $table) {
            $table->dropColumn(['retour_necessaire', 'statut_retour', 'date_retour_effectue']);
        });
    }
};
