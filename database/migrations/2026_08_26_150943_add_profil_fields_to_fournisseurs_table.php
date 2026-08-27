<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil fournisseur affiché dans l'Espace Coordinateur (Centre des
     * opérations) — téléphone déjà couvert par users.telephone, pas de
     * doublon ici.
     */
    public function up(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->string('nom_gerant')->nullable()->after('nom_entreprise');
            $table->string('horaires_ouverture')->nullable()->after('contact_pro');
            $table->string('lien_maps')->nullable()->after('horaires_ouverture');
        });
    }

    public function down(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->dropColumn(['nom_gerant', 'horaires_ouverture', 'lien_maps']);
        });
    }
};
