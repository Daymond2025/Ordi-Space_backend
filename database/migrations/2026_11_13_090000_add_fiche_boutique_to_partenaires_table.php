<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Écran "Profil" du partenaire (même écran que le Fournisseur) : contact
 * professionnel, horaires, lien Maps et gérant, éditables par le partenaire
 * lui-même. La zone couverte reste ses villes (partenaire_localite, Admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partenaires', function (Blueprint $table) {
            $table->string('contact_pro', 30)->nullable()->after('adresse_boutique');
            $table->string('horaires_ouverture')->nullable()->after('contact_pro');
            $table->string('lien_maps', 500)->nullable()->after('horaires_ouverture');
            $table->string('nom_gerant', 150)->nullable()->after('lien_maps');
            $table->string('telephone_gerant', 30)->nullable()->after('nom_gerant');
        });
    }

    public function down(): void
    {
        Schema::table('partenaires', function (Blueprint $table) {
            $table->dropColumn(['contact_pro', 'horaires_ouverture', 'lien_maps', 'nom_gerant', 'telephone_gerant']);
        });
    }
};
