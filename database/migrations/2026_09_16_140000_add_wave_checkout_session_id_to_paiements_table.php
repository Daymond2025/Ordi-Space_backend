<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            // Non chiffré (contrairement à reference_transaction) : identifiant
            // Wave, pas une donnée client sensible — doit rester indexable pour
            // retrouver le Paiement correspondant à chaque webhook reçu.
            $table->string('wave_checkout_session_id')->nullable()->unique()->after('reference_transaction');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropColumn('wave_checkout_session_id');
        });
    }
};
