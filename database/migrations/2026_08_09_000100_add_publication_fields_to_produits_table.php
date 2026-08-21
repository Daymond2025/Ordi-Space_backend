<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ouvre PRODUIT aux accessoires/logiciels publiés directement par
     * l'Administrateur (sans fournisseur ni validation coordinateur), et
     * distingue les articles à livraison physique des licences numériques.
     */
    public function up(): void
    {
        // fournisseur_id devient nullable : un produit publié par l'Admin
        // (accessoire/logiciel) n'a pas de fournisseur partenaire. Syntaxe
        // Schema Builder portable (fonctionne aussi sur SQLite en tests),
        // plutôt qu'un ALTER TABLE ... MODIFY spécifique à MySQL.
        Schema::table('produits', function (Blueprint $table) {
            $table->unsignedBigInteger('fournisseur_id')->nullable()->change();
        });

        Schema::table('produits', function (Blueprint $table) {
            $table->enum('type_livraison', ['physique', 'numerique'])->default('physique')->after('quantite_stock');
            $table->unsignedSmallInteger('duree_garantie_mois')->nullable()->after('type_livraison');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn(['type_livraison', 'duree_garantie_mois']);
        });

        Schema::table('produits', function (Blueprint $table) {
            $table->unsignedBigInteger('fournisseur_id')->nullable(false)->change();
        });
    }
};
