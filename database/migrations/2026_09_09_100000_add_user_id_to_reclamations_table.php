<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les réclamations ne viennent plus seulement des clients : fournisseurs,
 * commerciaux, livreurs et techniciens maintenance peuvent désormais aussi
 * en déposer (toutes ces entités sont, comme Client, des tables clé
 * `user_id` → `users.id` — pas de polymorphisme Eloquent nécessaire, un
 * simple FK générique suffit). `client_id` reste nullable et n'est peuplé
 * que lorsque l'auteur est effectivement un Client : Ordi'Space_Admin_Web
 * affiche déjà `reclamation.client.user.*` pour son écran "clients", ce
 * changement additif ne casse rien là-bas (vérifié — voir plan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reclamations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->restrictOnDelete();
        });

        // Backfill : toutes les réclamations existantes ont été déposées par
        // un client (seul rôle autorisé avant ce changement).
        DB::statement('UPDATE reclamations SET user_id = client_id WHERE user_id IS NULL');

        Schema::table('reclamations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreignId('client_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reclamations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->foreignId('client_id')->nullable(false)->change();
        });
    }
};
