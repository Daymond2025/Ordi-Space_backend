<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Écran de publication (Espace Coordinateur) : `prix` redevient
     * conceptuellement "prix partenaire" (jamais modifié par cet écran) ;
     * `prix_vente` est le prix réel après négociation, nul tant que le
     * produit n'est pas passé par ce nouvel écran — voir
     * ProduitController::publier().
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->decimal('prix_vente', 12, 2)->nullable()->after('prix');
            $table->decimal('commission_agent', 12, 2)->nullable()->after('cadeaux');
            $table->decimal('commission_apporteur', 12, 2)->nullable()->after('commission_agent');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn(['prix_vente', 'commission_agent', 'commission_apporteur']);
        });
    }
};
