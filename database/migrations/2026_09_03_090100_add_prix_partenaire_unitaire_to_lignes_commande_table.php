<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot du prix partenaire (produits.prix) au moment de la commande,
     * distinct de `prix_unitaire` (désormais le prix de vente facturé au
     * client) — permet à Commande::crediterFournisseursSiEligible() de payer
     * le fournisseur sur le prix partenaire plutôt que sur le prix de vente
     * marqué. Nullable : les lignes créées avant cette fonctionnalité
     * gardent l'ancien calcul taux_commission sur prix_unitaire.
     */
    public function up(): void
    {
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->decimal('prix_partenaire_unitaire', 12, 2)->nullable()->after('prix_unitaire');
        });
    }

    public function down(): void
    {
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->dropColumn('prix_partenaire_unitaire');
        });
    }
};
