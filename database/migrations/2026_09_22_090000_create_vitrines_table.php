<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Mon lien de vente" + "Affiche Vitrine & QR" (onglet Profil de la
     * Boutique) : une vitrine par utilisateur — un lien unique donnant accès
     * à toute sa sélection de produits, et l'affiche A4 dont le QR pointe
     * dessus. Rattachée à `users` (pas au seul livreur) : d'autres entités
     * partageront des liens à leur tour. `clics` compte les ouvertures du
     * lien, `scans` celles arrivées par le QR de l'affiche.
     */
    public function up(): void
    {
        Schema::create('vitrines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->unsignedInteger('clics')->default(0);
            $table->unsignedInteger('scans')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vitrines');
    }
};
