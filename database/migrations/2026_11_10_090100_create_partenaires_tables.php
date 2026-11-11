<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partenaire Ordi'Space : profil, villes couvertes, prix de cession
 * (remise par défaut + exceptions par produit), prix de revente fixés par
 * le partenaire, stock tracé par mouvements, approvisionnements payés
 * d'avance et commandes de ses clients — tables dédiées, volontairement
 * séparées de `commandes` (circuit Ordi'Space : coordinateur, commissions
 * fournisseurs, livreurs) pour qu'aucune vente partenaire n'y remonte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partenaires', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('nom_boutique', 150);
            $table->string('adresse_boutique')->nullable();
            // Remise (%) sur le prix Ordi'Space, appliquée à tout produit
            // sans tarif d'exception (tarifs_partenaire).
            $table->decimal('remise_defaut', 5, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('partenaire_localite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partenaire_id')->constrained('partenaires', 'user_id')->cascadeOnDelete();
            $table->foreignId('localite_id')->constrained('localites')->cascadeOnDelete();
            $table->unique(['partenaire_id', 'localite_id']);
        });

        Schema::create('tarifs_partenaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partenaire_id')->constrained('partenaires', 'user_id')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->decimal('prix_cession', 12, 2);
            $table->timestamps();
            $table->unique(['partenaire_id', 'produit_id']);
        });

        Schema::create('offres_partenaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partenaire_id')->constrained('partenaires', 'user_id')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->decimal('prix_revente', 12, 2);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['partenaire_id', 'produit_id']);
        });

        Schema::create('stocks_partenaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partenaire_id')->constrained('partenaires', 'user_id')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->unsignedInteger('quantite')->default(0);
            $table->timestamps();
            $table->unique(['partenaire_id', 'produit_id']);
        });

        Schema::create('approvisionnements_partenaire', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('partenaire_id')->constrained('partenaires', 'user_id')->cascadeOnDelete();
            $table->string('statut', 30)->index();
            $table->decimal('montant_total', 12, 2);
            $table->string('mode_paiement', 30)->nullable();
            $table->string('reference_paiement', 100)->nullable();
            // Disque privé : servie uniquement au partenaire et à l'Admin.
            $table->string('preuve_paiement')->nullable();
            $table->string('motif_refus', 500)->nullable();
            $table->timestamp('paiement_declare_le')->nullable();
            $table->timestamp('paiement_valide_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expedie_le')->nullable();
            $table->timestamp('recu_le')->nullable();
            $table->timestamp('annule_le')->nullable();
            $table->timestamps();
        });

        Schema::create('lignes_approvisionnement_partenaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approvisionnement_id')->constrained('approvisionnements_partenaire')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits');
            $table->unsignedInteger('quantite');
            // Figé à la création : un changement de tarif ultérieur ne
            // touche pas un approvisionnement déjà demandé.
            $table->decimal('prix_cession_unitaire', 12, 2);
        });

        Schema::create('commandes_partenaire', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('partenaire_id')->constrained('partenaires', 'user_id');
            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('localite_id')->constrained('localites');
            $table->string('mode_remise', 20);
            $table->string('adresse_livraison')->nullable();
            $table->string('telephone_contact', 30);
            $table->string('note', 500)->nullable();
            $table->string('statut', 30)->index();
            $table->decimal('montant_total', 12, 2);
            // true tant que les quantités sont sorties du stock du partenaire
            // (remises en stock si la commande est annulée ensuite).
            $table->boolean('stock_sorti')->default(false);
            $table->string('motif_annulation', 500)->nullable();
            $table->timestamp('acceptee_le')->nullable();
            $table->timestamp('expediee_le')->nullable();
            $table->timestamp('livree_le')->nullable();
            $table->timestamp('annulee_le')->nullable();
            $table->timestamps();
        });

        Schema::create('lignes_commande_partenaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_partenaire_id')->constrained('commandes_partenaire')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits');
            $table->unsignedInteger('quantite');
            // Prix payé par le client et prix de cession au moment de la
            // commande : bénéfice exact, insensible aux changements de prix
            // ultérieurs. Le prix de cession n'est jamais renvoyé au client.
            $table->decimal('prix_unitaire', 12, 2);
            $table->decimal('prix_cession_unitaire', 12, 2);
            $table->unsignedSmallInteger('duree_garantie_mois')->nullable();
        });

        Schema::create('mouvements_stock_partenaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partenaire_id')->constrained('partenaires', 'user_id')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits');
            $table->string('type', 30)->index();
            $table->integer('quantite'); // positif = entrée, négatif = sortie
            $table->unsignedInteger('stock_apres');
            $table->foreignId('approvisionnement_id')->nullable()->constrained('approvisionnements_partenaire')->nullOnDelete();
            $table->foreignId('commande_partenaire_id')->nullable()->constrained('commandes_partenaire')->nullOnDelete();
            $table->string('motif', 255)->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('demandes_sav', function (Blueprint $table) {
            // Produit vendu par un partenaire : la demande va d'abord chez
            // lui ; Ordi'Space ne la voit qu'une fois transférée.
            $table->foreignId('partenaire_id')->nullable()->after('garantie_id')->constrained('partenaires', 'user_id')->nullOnDelete();
            $table->foreignId('ligne_commande_partenaire_id')->nullable()->after('partenaire_id')->constrained('lignes_commande_partenaire')->nullOnDelete();
            $table->timestamp('transferee_le')->nullable()->after('ligne_commande_partenaire_id');
            $table->string('motif_transfert', 500)->nullable()->after('transferee_le');
        });
    }

    public function down(): void
    {
        Schema::table('demandes_sav', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ligne_commande_partenaire_id');
            $table->dropConstrainedForeignId('partenaire_id');
            $table->dropColumn(['transferee_le', 'motif_transfert']);
        });

        Schema::dropIfExists('mouvements_stock_partenaire');
        Schema::dropIfExists('lignes_commande_partenaire');
        Schema::dropIfExists('commandes_partenaire');
        Schema::dropIfExists('lignes_approvisionnement_partenaire');
        Schema::dropIfExists('approvisionnements_partenaire');
        Schema::dropIfExists('stocks_partenaire');
        Schema::dropIfExists('offres_partenaire');
        Schema::dropIfExists('tarifs_partenaire');
        Schema::dropIfExists('partenaire_localite');
        Schema::dropIfExists('partenaires');
    }
};
