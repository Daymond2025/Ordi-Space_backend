<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solde du portefeuille commercial ("Mes paiements", app Commercial) —
 * même esprit que Fournisseur::solde_portefeuille, mis à jour par
 * Commercial::crediterPortefeuille()/debiterPortefeuille().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerciaux', function (Blueprint $table) {
            $table->decimal('solde_portefeuille', 12, 2)->default(0)->after('localisation');
        });
    }

    public function down(): void
    {
        Schema::table('commerciaux', function (Blueprint $table) {
            $table->dropColumn('solde_portefeuille');
        });
    }
};
