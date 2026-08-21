<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->unique()->constrained('commandes')->cascadeOnDelete();
            // Nullable : encaissement futur possible sans livreur (paiement en ligne).
            $table->foreignId('livreur_id')->nullable()->constrained('livreurs', 'user_id')->nullOnDelete();
            $table->decimal('montant', 12, 2);
            $table->enum('mode_paiement', ['mobile_money', 'especes']);
            $table->enum('statut_paiement', ['en_attente', 'confirme', 'echoue'])->default('en_attente')->index();
            // Chiffré au niveau applicatif (cast `encrypted` sur le modèle Paiement).
            $table->text('reference_transaction')->nullable();
            $table->timestamp('date_paiement')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
