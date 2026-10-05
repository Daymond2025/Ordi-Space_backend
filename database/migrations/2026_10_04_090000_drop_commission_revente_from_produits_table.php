<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le livreur a rejoint le maintenancier comme "apporteur d'affaire"
     * (retour du PDG) : il n'a plus de champ de commission qui lui soit
     * propre, c'est désormais la même `commission_apporteur` que tout autre
     * apporteur — voir VenteBoutique::enregistrer() et
     * ProduitController::publier()/modifierBoutique(). Sans effet sur les
     * ventes déjà enregistrées : `ventes_boutique.commission` est une
     * commission figée à la vente, indépendante de cette colonne.
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('commission_revente');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->decimal('commission_revente', 12, 2)->nullable()->after('commission_apporteur');
        });
    }
};
