<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Montant de commission réellement prélevé à la vente (distinct du
     * montant net crédité au fournisseur) — nécessaire pour afficher
     * "Commission totale, reçu" sur l'accueil Coordinateur sans le
     * recalculer à partir du taux ACTUEL du fournisseur (imprécis si le
     * taux a changé depuis). Nullable : les crédits déjà existants avant ce
     * correctif n'ont pas cette donnée.
     */
    public function up(): void
    {
        Schema::table('transactions_portefeuille_fournisseurs', function (Blueprint $table) {
            $table->decimal('commission_prelevee', 12, 2)->nullable()->after('montant');
        });
    }

    public function down(): void
    {
        Schema::table('transactions_portefeuille_fournisseurs', function (Blueprint $table) {
            $table->dropColumn('commission_prelevee');
        });
    }
};
