<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique du portefeuille commercial ("Mes paiements") — un crédit
 * "Commande validée" par commande validée (commission_agent cumulé de ses
 * lignes, posé au produit), repris en débit "Retrait effectué" si la
 * commande est ensuite annulée — voir Commande::crediterCommissionCommercialSiEligible()/
 * reprendreCommissionCommercialSiEligible(). `client_nom`/`client_localite`
 * sont figés au moment de la transaction (même esprit que Commande::versApercu()) :
 * l'historique reste correct même si l'adresse du client change ensuite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions_portefeuille_commerciaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commercial_id')->constrained('commerciaux', 'user_id')->cascadeOnDelete();
            $table->foreignId('commande_id')->nullable()->constrained('commandes')->nullOnDelete();
            $table->enum('type', ['credit', 'debit']);
            $table->decimal('montant', 12, 2);
            $table->string('libelle');
            $table->string('client_nom')->nullable();
            $table->string('client_localite')->nullable();
            $table->decimal('solde_apres', 12, 2);
            $table->dateTime('date_transaction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions_portefeuille_commerciaux');
    }
};
