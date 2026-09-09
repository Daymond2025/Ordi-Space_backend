<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi du reversement du cash COD encaissé par le livreur — date_limite_depot
 * posée à l'encaissement (mode espèces uniquement), date_depot quand le
 * livreur confirme avoir reversé. Cf. PaiementController::encaisser()/deposer().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->timestamp('date_limite_depot')->nullable()->after('date_paiement');
            $table->timestamp('date_depot')->nullable()->after('date_limite_depot');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropColumn(['date_limite_depot', 'date_depot']);
        });
    }
};
