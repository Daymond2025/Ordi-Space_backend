<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Boutique" — état déclaratif (neuf/quasi neuf/occasion/reconditionné,
     * voir ETATS_PRODUIT), pourcentage de réduction et prix barré affichés
     * sur les cartes produit. `prix_barre` est un prix de référence marketing
     * (ex. prix constructeur), distinct de `prix` (coût fournisseur, jamais
     * montré au revendeur) et de `prix_vente` (prix public réel).
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('etat_produit')->nullable()->after('couleur');
            $table->unsignedTinyInteger('pourcentage_reduction')->nullable()->after('commission_revente');
            $table->decimal('prix_barre', 12, 2)->nullable()->after('pourcentage_reduction');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn(['etat_produit', 'pourcentage_reduction', 'prix_barre']);
        });
    }
};
