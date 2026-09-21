<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réglages globaux de la plateforme, modifiables par l'Administrateur
     * sans redéploiement (clé → valeur). Premier usage : le numéro du support
     * Ordi'Space affiché aux livreurs ("Support Partenaire" de l'écran
     * "Mes infos"). Voir Parametre.
     */
    public function up(): void
    {
        Schema::create('parametres', function (Blueprint $table) {
            $table->id();
            $table->string('cle', 64)->unique();
            $table->text('valeur')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres');
    }
};
