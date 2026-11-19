<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commerciaux internes / indépendants :
 * - commerciaux.categorie_commercial : "interne" (salarié, 1 000 F par
 *   produit vendu) ou "independant" (recruté, commission propre au produit,
 *   2 500 F par défaut). Les comptes existants deviennent "interne".
 * - produits.commission_commercial_independant : montant par produit vendu
 *   pour un commercial indépendant, modifiable par l'Admin (null = défaut).
 * - commandes.commission_commercial : montant figé au moment où la commission
 *   est créditée, pour que les statistiques ne bougent plus si l'Admin
 *   change ensuite le montant du produit ou le type du commercial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerciaux', function (Blueprint $table) {
            $table->string('categorie_commercial', 20)->default('interne')->after('type_commercial');
        });

        Schema::table('produits', function (Blueprint $table) {
            $table->decimal('commission_commercial_independant', 12, 2)->nullable()->after('commission_agent');
        });

        Schema::table('commandes', function (Blueprint $table) {
            $table->decimal('commission_commercial', 12, 2)->nullable()->after('commercial_id');
        });

        // Historique : les commissions déjà créditées gardent leur montant réel.
        DB::table('transactions_portefeuille_commerciaux')
            ->where('type', 'credit')
            ->whereNotNull('commande_id')
            ->orderBy('id')
            ->each(function ($transaction) {
                DB::table('commandes')->where('id', $transaction->commande_id)->update(['commission_commercial' => $transaction->montant]);
            });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('commission_commercial');
        });

        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('commission_commercial_independant');
        });

        Schema::table('commerciaux', function (Blueprint $table) {
            $table->dropColumn('categorie_commercial');
        });
    }
};
