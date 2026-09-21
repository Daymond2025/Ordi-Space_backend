<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Boutique" — commission qu'un revendeur (Livreur pour l'instant, le
     * concept est pensé pour s'étendre à d'autres rôles) touche s'il vend ce
     * produit via son lien affilié. Renseignée par Fournisseur/Coordinateur/
     * Admin à la création du produit — sans rapport avec commission_agent
     * (rémunération Commercial) ni commission_apporteur (25% auto-calculé,
     * jamais utilisé ailleurs dans le code).
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->decimal('commission_revente', 12, 2)->nullable()->after('commission_apporteur');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('commission_revente');
        });
    }
};
