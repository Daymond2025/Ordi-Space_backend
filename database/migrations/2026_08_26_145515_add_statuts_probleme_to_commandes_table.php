<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Espace Coordinateur : un coordinateur peut signaler un problème sur une
     * commande en_attente (numéro incorrect, client injoignable, report)
     * avant de la valider ou de l'annuler — voir CommandeController::traiterProbleme().
     */
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->enum('statut_commande', [
                'en_attente', 'validee', 'en_preparation', 'en_livraison', 'livree', 'annulee',
                'reportee', 'client_injoignable', 'numero_incorrect',
            ])->default('en_attente')->change();
        });
    }

    /**
     * Attention : échoue si des commandes portent déjà un des 3 nouveaux
     * statuts — les repasser à 'en_attente' avant de rollback.
     */
    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->enum('statut_commande', [
                'en_attente', 'validee', 'en_preparation', 'en_livraison', 'livree', 'annulee',
            ])->default('en_attente')->change();
        });
    }
};
