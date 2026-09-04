<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Écran "Profil fournisseur" (Centre des opérations) — zone_couverte
     * même concept que livreurs.zone_couverture, jamais repris côté
     * Fournisseur jusqu'ici ; telephone_gerant distinct de contact_pro
     * (contact de l'entreprise) et de users.telephone (compte de connexion).
     */
    public function up(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->string('zone_couverte')->nullable()->after('lien_maps');
            $table->string('telephone_gerant')->nullable()->after('nom_gerant');
        });
    }

    public function down(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->dropColumn(['zone_couverte', 'telephone_gerant']);
        });
    }
};
