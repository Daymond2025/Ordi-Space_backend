<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la valeur "supprime" à l'enum statut_compte — une "suppression" de
 * compte reste une désactivation définitive (jamais de DELETE physique, cf.
 * AdminCrudRolesTest::test_desactiver_un_fournisseur_un_livreur_et_un_coordinateur()),
 * mais distincte de "desactive" : irréversible depuis l'interface (pas de
 * bouton "Réactiver" une fois ce statut atteint), alors que desactive/suspendu
 * restent réversibles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('statut_compte', ['actif', 'suspendu', 'desactive', 'supprime'])
                ->default('actif')->change();
        });
    }

    public function down(): void
    {
        DB::table('users')->where('statut_compte', 'supprime')->update(['statut_compte' => 'desactive']);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('statut_compte', ['actif', 'suspendu', 'desactive'])
                ->default('actif')->change();
        });
    }
};
