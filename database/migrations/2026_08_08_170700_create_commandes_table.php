<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients', 'user_id')->restrictOnDelete();
            $table->foreignId('commercial_id')->constrained('commerciaux', 'user_id')->restrictOnDelete();
            $table->foreignId('coordinateur_id')->nullable()->constrained('coordinateurs', 'user_id')->nullOnDelete();
            $table->foreignId('canal_vente_id')->constrained('canaux_vente')->restrictOnDelete();
            $table->enum('statut_commande', [
                'en_attente', 'validee', 'en_preparation', 'en_livraison', 'livree', 'annulee',
            ])->default('en_attente')->index();
            $table->decimal('montant_total', 12, 2)->default(0);
            $table->timestamp('date_commande')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commandes');
    }
};
