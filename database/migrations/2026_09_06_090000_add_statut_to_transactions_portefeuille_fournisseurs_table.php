<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Écran "Portefeuille fournisseur" (Centre des opérations) — statut de
     * règlement, uniquement pertinent pour les crédits (une vente livrée est
     * "en_attente" jusqu'à ce qu'un paiement groupé la bascule en "paye").
     * Les débits représentent un paiement déjà effectué : pas de statut.
     */
    public function up(): void
    {
        Schema::table('transactions_portefeuille_fournisseurs', function (Blueprint $table) {
            $table->string('statut')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('transactions_portefeuille_fournisseurs', function (Blueprint $table) {
            $table->dropColumn('statut');
        });
    }
};
