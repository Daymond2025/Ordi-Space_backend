<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Complète duree_garantie_mois pour les durées courtes (1/2/3 semaines)
     * négociées sur l'écran "Nouvelle commande" — un nombre de mois entier ne
     * peut pas représenter une semaine. Garantie::genererPourCommande()
     * utilise cette colonne quand elle est renseignée, sinon retombe sur
     * duree_garantie_mois comme avant. Les deux ne sont jamais renseignées
     * en même temps pour une même ligne.
     */
    public function up(): void
    {
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->unsignedSmallInteger('duree_garantie_jours')->nullable()->after('duree_garantie_mois');
        });
    }

    public function down(): void
    {
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->dropColumn('duree_garantie_jours');
        });
    }
};
