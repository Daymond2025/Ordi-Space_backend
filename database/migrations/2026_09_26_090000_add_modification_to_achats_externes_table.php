<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Modifier le montant" (Centre de paiement des commissions) — le
     * fournisseur peut proposer un montant réduit avec un motif, mais ça ne
     * change JAMAIS `commission_due` directement : tant qu'un Coordinateur
     * n'a pas approuvé (fonctionnalité pas encore construite, futur mockup
     * côté Coordinateur), le montant ORIGINAL reste le seul dû — voir
     * ProduitController::demanderModificationAchatExterne().
     */
    public function up(): void
    {
        Schema::table('achats_externes', function (Blueprint $table) {
            $table->decimal('montant_modifie_propose', 10, 2)->nullable()->after('commission_due');
            $table->text('motif_modification')->nullable()->after('montant_modifie_propose');
            $table->string('statut_modification', 20)->nullable()->after('motif_modification');
        });
    }

    public function down(): void
    {
        Schema::table('achats_externes', function (Blueprint $table) {
            $table->dropColumn(['montant_modifie_propose', 'motif_modification', 'statut_modification']);
        });
    }
};
