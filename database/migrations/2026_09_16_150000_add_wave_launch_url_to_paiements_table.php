<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            // Wave ne renvoie wave_launch_url qu'à la création de la session
            // (pas via GET /checkout/sessions/:id) — on le garde pour pouvoir
            // réafficher le QR si le livreur quitte puis revient sur l'écran
            // de paiement avant confirmation.
            $table->string('wave_launch_url')->nullable()->after('wave_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropColumn('wave_launch_url');
        });
    }
};
