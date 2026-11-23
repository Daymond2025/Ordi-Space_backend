<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications « persistantes avec sonnerie » :
 * - lien : écran à ouvrir quand on touche la notification (ex. /commande/12) ;
 * - urgente : la sonnerie se répète dans l'app tant qu'elle n'est pas lue ;
 * - commandes.coordination_notifiee_le : la coordination n'est prévenue
 *   d'une commande programmée qu'à sa date de transmission (pas de tâche
 *   planifiée sur l'hébergement, voir NotificationService). Les commandes
 *   existantes sont marquées comme déjà notifiées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_ordispace', function (Blueprint $table) {
            $table->string('lien')->nullable()->after('contenu');
            $table->boolean('urgente')->default(false)->after('lien');
            $table->index(['user_id', 'lu']);
        });

        Schema::table('commandes', function (Blueprint $table) {
            $table->timestamp('coordination_notifiee_le')->nullable()->after('date_transmission');
        });

        DB::table('commandes')->update(['coordination_notifiee_le' => now()]);
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('coordination_notifiee_le');
        });

        Schema::table('notifications_ordispace', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'lu']);
            $table->dropColumn(['lien', 'urgente']);
        });
    }
};
