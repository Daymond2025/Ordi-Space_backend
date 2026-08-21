<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions_portefeuille', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients', 'user_id')->restrictOnDelete();
            $table->enum('type', ['credit', 'debit']);
            $table->decimal('montant', 12, 2);
            $table->string('motif');
            $table->foreignId('commande_id')->nullable()->constrained('commandes')->nullOnDelete();
            $table->decimal('solde_apres', 12, 2);
            $table->timestamp('date_transaction')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions_portefeuille');
    }
};
