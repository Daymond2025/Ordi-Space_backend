<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la valeur 'assignee' à l'enum statut_livraison — représente une
 * livraison assignée par le coordinateur mais pas encore acceptée par le
 * livreur (distincte de 'en_cours', qui signifiait jusqu'ici "assignée" ET
 * "acceptée" en une seule étape). Laravel 12 gère change() nativement sur
 * MySQL et SQLite (utilisé par les tests) sans doctrine/dbal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('livraisons', function (Blueprint $table) {
            $table->enum('statut_livraison', [
                'en_preparation', 'en_attente_livreur', 'assignee', 'en_cours', 'livree', 'echouee',
            ])->default('en_preparation')->change();
        });
    }

    public function down(): void
    {
        Schema::table('livraisons', function (Blueprint $table) {
            $table->enum('statut_livraison', [
                'en_preparation', 'en_attente_livreur', 'en_cours', 'livree', 'echouee',
            ])->default('en_preparation')->change();
        });
    }
};
