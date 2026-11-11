<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des commandes partenaire = même processus que le circuit Ordi'Space
 * (mêmes statuts, mêmes conversations produit/commande, même timeline).
 * Tables dédiées : seuls le partenaire et le coordinateur/admin voient ces
 * conversations — jamais le fournisseur (et inversement).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Alignement sur les statuts Ordi'Space (statut_commande).
        DB::table('commandes_partenaire')->where('statut', 'acceptee')->update(['statut' => 'validee']);
        DB::table('commandes_partenaire')->where('statut', 'en_attente_stock')->update(['statut' => 'en_attente']);
        DB::table('commandes_partenaire')->where('statut', 'refusee')->update(['statut' => 'annulee']);

        Schema::create('messages_partenaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partenaire_id')->constrained('partenaires', 'user_id')->cascadeOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->foreignId('commande_partenaire_id')->nullable()->constrained('commandes_partenaire')->cascadeOnDelete();
            $table->foreignId('auteur_id')->constrained('users');
            $table->string('type', 30);
            $table->text('contenu')->nullable();
            $table->string('fichier')->nullable();
            $table->json('donnees')->nullable();
            $table->timestamp('date_envoi')->useCurrent()->index();
            $table->index(['partenaire_id', 'produit_id']);
        });

        Schema::create('suivis_commande_partenaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_partenaire_id')->constrained('commandes_partenaire')->cascadeOnDelete();
            $table->foreignId('acteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('details', 500)->nullable();
            $table->string('statut_avant', 30)->nullable();
            $table->string('statut_apres', 30)->nullable();
            $table->timestamp('date_heure')->useCurrent();
        });

        // Dernière consultation d'un fil (badges "nouvelles activités").
        Schema::create('consultations_partenaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('cible', 20); // produit | commande
            $table->unsignedBigInteger('partenaire_id');
            $table->unsignedBigInteger('cible_id');
            $table->timestamp('consulte_le');
            $table->unique(['user_id', 'cible', 'partenaire_id', 'cible_id'], 'consultations_partenaire_unique');
        });

        // Historique des commandes déjà créées : leur événement de création.
        DB::table('commandes_partenaire')->orderBy('id')->each(function ($c) {
            DB::table('suivis_commande_partenaire')->insert([
                'commande_partenaire_id' => $c->id, 'acteur_id' => $c->client_id, 'action' => 'commande_creee',
                'details' => 'Commande passée par le client.', 'statut_apres' => 'en_attente', 'date_heure' => $c->created_at,
            ]);
            if ($c->statut !== 'en_attente') {
                DB::table('suivis_commande_partenaire')->insert([
                    'commande_partenaire_id' => $c->id, 'acteur_id' => $c->partenaire_id, 'action' => 'statut_change',
                    'details' => 'Statut mis à jour.', 'statut_avant' => 'en_attente', 'statut_apres' => $c->statut, 'date_heure' => $c->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations_partenaire');
        Schema::dropIfExists('suivis_commande_partenaire');
        Schema::dropIfExists('messages_partenaire');
    }
};
