<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référence de paiement (Wave, Orange Money, etc.) — saisie par le
     * coordinateur au moment où il règle réellement un fournisseur (aucun
     * moyen de paiement intégré à l'app), copiée sur chaque crédit réglé par
     * ce paiement. Reste nulle tant que la vente est en_attente.
     */
    public function up(): void
    {
        Schema::table('transactions_portefeuille_fournisseurs', function (Blueprint $table) {
            $table->string('reference_paiement')->nullable()->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('transactions_portefeuille_fournisseurs', function (Blueprint $table) {
            $table->dropColumn('reference_paiement');
        });
    }
};
