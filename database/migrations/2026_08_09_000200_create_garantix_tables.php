<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GarantiX : abonnement annuel payant de maintenance étendue, distinct
     * de la garantie automatique incluse à l'achat (table `garanties`).
     * Catalogue géré par l'Administrateur (formules + prestations +
     * exclusions), souscrit par le client sur un achat précis.
     */
    public function up(): void
    {
        Schema::create('formules_garantix', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('libelle_complet');
            $table->string('libelle_badge')->nullable();
            $table->decimal('prix_annuel', 12, 2);
            $table->unsignedTinyInteger('frequence_interventions');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('ordre_affichage')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('prestations_garantix', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formule_garantix_id')->constrained('formules_garantix')->cascadeOnDelete();
            $table->string('libelle');
            $table->unsignedSmallInteger('ordre_affichage')->default(0);
            $table->timestamps();
        });

        Schema::create('exclusions_garantix', function (Blueprint $table) {
            $table->id();
            $table->string('libelle');
            $table->unsignedSmallInteger('ordre_affichage')->default(0);
            $table->timestamps();
        });

        Schema::create('abonnements_garantix', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients', 'user_id')->restrictOnDelete();
            $table->foreignId('ligne_commande_id')->constrained('lignes_commande')->restrictOnDelete();
            $table->foreignId('formule_garantix_id')->constrained('formules_garantix')->restrictOnDelete();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->enum('statut', ['actif', 'expire', 'resilie'])->default('actif');
            $table->unsignedSmallInteger('interventions_utilisees')->default(0);
            $table->enum('mode_paiement', ['mobile_money', 'especes']);
            $table->text('reference_transaction')->nullable();
            $table->enum('statut_paiement', ['en_attente', 'confirme', 'echoue'])->default('confirme');
            $table->timestamp('date_paiement')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abonnements_garantix');
        Schema::dropIfExists('exclusions_garantix');
        Schema::dropIfExists('prestations_garantix');
        Schema::dropIfExists('formules_garantix');
    }
};
