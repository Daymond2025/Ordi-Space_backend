<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Privilège Space : catalogue de promotions publiées par l'Admin
     * (remise %, remise fixe, livraison gratuite, parrainage), chacune
     * activable via un code au passage de commande.
     */
    public function up(): void
    {
        Schema::create('privileges', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->string('sous_titre')->nullable();
            $table->text('description')->nullable();
            $table->enum('type_privilege', [
                'remise_pourcentage', 'remise_montant', 'livraison_gratuite', 'parrainage',
            ]);
            $table->decimal('valeur', 12, 2)->nullable();
            $table->string('code_promo')->unique();
            // null = illimité ; 1 = usage unique par client ; N = N fois par client.
            $table->unsignedInteger('limite_utilisation_par_client')->nullable();
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->boolean('actif')->default(true);
            $table->unsignedSmallInteger('ordre_affichage')->default(0);
            $table->timestamps();
        });

        Schema::create('utilisations_privilege', function (Blueprint $table) {
            $table->id();
            $table->foreignId('privilege_id')->constrained('privileges')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients', 'user_id')->restrictOnDelete();
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $table->decimal('montant_remise', 12, 2);
            $table->timestamp('date_utilisation')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utilisations_privilege');
        Schema::dropIfExists('privileges');
    }
};
