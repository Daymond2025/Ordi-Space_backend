<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Champs de profil affichés sur l'écran "Profil commercial" (Espace Agent) —
 * saisis manuellement par le coordinateur (pas de génération automatique),
 * même esprit que Fournisseur::adresse_entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerciaux', function (Blueprint $table) {
            $table->string('nom_entreprise')->nullable()->after('matricule');
            $table->string('localisation')->nullable()->after('nom_entreprise');
        });
    }

    public function down(): void
    {
        Schema::table('commerciaux', function (Blueprint $table) {
            $table->dropColumn(['nom_entreprise', 'localisation']);
        });
    }
};
