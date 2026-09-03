<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sépare le fil de négociation de prix (coordinateur ↔ fournisseur) du
     * fil de discussion produit général sur la même table `messages` —
     * indexProduit()/conversationProduit() excluent ces messages, les
     * nouveaux endpoints de négociation ne renvoient qu'eux.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('est_negociation_prix')->default(false)->after('donnees');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('est_negociation_prix');
        });
    }
};
