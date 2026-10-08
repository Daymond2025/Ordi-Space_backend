<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durcissement anti-double-crédit (audit sécurité) : Commande::crediterCommissionCommercialSiEligible()/
 * reprendreCommissionCommercialSiEligible() protègent déjà l'idempotence par
 * un simple "exists() puis insert", lisible/écrivable en lecture sale par
 * deux requêtes concurrentes (ex. "valider" rejouée deux fois de suite par
 * le Coordinateur). Cette contrainte unique transforme toute course
 * résiduelle en erreur d'intégrité proprement attrapée côté appli plutôt
 * qu'en double crédit silencieux. `commande_id` reste nullable (débit
 * "Retrait effectué" de l'Admin, sans commande) : MySQL autorise plusieurs
 * NULL dans un index unique, donc ces lignes-là ne sont jamais en conflit
 * entre elles — seul (commande_id, type) non-null est contraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions_portefeuille_commerciaux', function (Blueprint $table) {
            $table->unique(['commande_id', 'type'], 'transactions_portefeuille_commerciaux_commande_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions_portefeuille_commerciaux', function (Blueprint $table) {
            $table->dropUnique('transactions_portefeuille_commerciaux_commande_type_unique');
        });
    }
};
