<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retraits du portefeuille de commissions (onglet Portefeuille de la
     * Boutique). Le livreur demande un montant vers un numéro Mobile Money ;
     * l'Admin paie hors de l'app puis valide (avec la référence du transfert)
     * ou refuse (avec un motif). Tant qu'une demande est "en_attente" ou
     * "valide", son montant est déduit de la commission disponible ; refusée
     * ou annulée, il est restitué — voir PortefeuilleCommissions. Rattachée à
     * `users` (pas au seul livreur) : d'autres entités auront un portefeuille.
     */
    public function up(): void
    {
        Schema::create('demandes_retrait', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('montant', 12, 2);
            $table->string('operateur', 20);
            $table->string('telephone', 30);
            $table->string('statut', 20)->default('en_attente');
            // Référence du transfert (statut valide) ou motif du refus (statut refuse).
            $table->string('reference', 100)->nullable();
            $table->string('remarque')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traite_le')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_retrait');
    }
};
