<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            // Taux négocié par fournisseur (pas global) — défaut 10% pour
            // tout fournisseur existant/nouveau tant qu'un Admin ne l'ajuste pas.
            $table->decimal('taux_commission', 5, 2)->default(10.00)->after('lien_maps');
            // Solde signé : positif = OrdiSpace doit au fournisseur, négatif
            // = le fournisseur doit à OrdiSpace — même précision que clients.solde_portefeuille.
            $table->decimal('solde_portefeuille', 12, 2)->default(0)->after('taux_commission');
        });
    }

    public function down(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->dropColumn(['taux_commission', 'solde_portefeuille']);
        });
    }
};
