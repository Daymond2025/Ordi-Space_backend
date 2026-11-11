<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commande passée par le partenaire depuis son catalogue sans assez de
 * stock : un approvisionnement des articles manquants lui est rattaché. Le
 * partenaire le paie hors application (l'écran "Confirmation" Wave n'est
 * qu'une formalité) ; Ordi'Space valide, expédie, et la commande du client
 * est validée automatiquement à la réception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvisionnements_partenaire', function (Blueprint $table) {
            $table->foreignId('commande_partenaire_id')->nullable()->after('partenaire_id')->constrained('commandes_partenaire')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approvisionnements_partenaire', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commande_partenaire_id');
        });
    }
};
