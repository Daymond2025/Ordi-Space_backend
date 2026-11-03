<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Statut propre au rendez-vous (écran d'accueil de l'app Maintenancier :
 * Demande / Confirmé / Reporté / Refusé / Terminé), distinct du statut de
 * la demande SAV. Un rendez-vous planifié par le staff (store()) est
 * confirmé d'office ; "demande" est réservé à un rendez-vous demandé par le
 * client et pas encore accepté par le technicien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->enum('statut_rdv', ['demande', 'confirme', 'reporte', 'refuse', 'termine'])
                ->default('confirme')->after('lieu')->index();
        });

        DB::table('rendez_vous')
            ->whereIn('id', DB::table('interventions')->where('statut_intervention', 'terminee')->select('rendez_vous_id'))
            ->update(['statut_rdv' => 'termine']);
    }

    public function down(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->dropIndex(['statut_rdv']);
            $table->dropColumn('statut_rdv');
        });
    }
};
