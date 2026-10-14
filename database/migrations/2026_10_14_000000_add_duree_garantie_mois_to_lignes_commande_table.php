<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Surcharge admin de la durée de garantie sur une commande enregistrée
     * manuellement (écran "Nouvelle commande") — même rôle que
     * prix_unitaire/prix_partenaire_unitaire déjà sur cette table : quand
     * absente, Garantie::genererPourCommande() retombe sur
     * Produit::duree_garantie_mois comme aujourd'hui.
     */
    public function up(): void
    {
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->unsignedSmallInteger('duree_garantie_mois')->nullable()->after('prix_partenaire_unitaire');
        });
    }

    public function down(): void
    {
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->dropColumn('duree_garantie_mois');
        });
    }
};
