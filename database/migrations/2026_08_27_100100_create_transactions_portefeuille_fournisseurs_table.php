<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger fournisseur — même schéma que transactions_portefeuille (client),
     * plus acteur_id pour la traçabilité (null = crédit automatique système,
     * sinon l'utilisateur qui a enregistré un paiement manuel).
     */
    public function up(): void
    {
        Schema::create('transactions_portefeuille_fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fournisseur_id')->constrained('fournisseurs', 'user_id')->restrictOnDelete();
            $table->enum('type', ['credit', 'debit']);
            $table->decimal('montant', 12, 2);
            $table->string('motif');
            $table->foreignId('commande_id')->nullable()->constrained('commandes')->nullOnDelete();
            $table->foreignId('acteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('solde_apres', 12, 2);
            $table->timestamp('date_transaction')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions_portefeuille_fournisseurs');
    }
};
