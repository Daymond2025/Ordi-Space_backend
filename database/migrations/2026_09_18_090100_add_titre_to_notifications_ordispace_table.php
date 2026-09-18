<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "contenu" restait un simple texte jusqu'ici (seul appelant :
     * Admin\ClientController). L'écran "Notifications" (app Livreur) affiche
     * un titre en gras distinct de la description — plutôt que d'encoder les
     * deux dans "contenu" par une convention fragile, un vrai champ "titre".
     */
    public function up(): void
    {
        Schema::table('notifications_ordispace', function (Blueprint $table) {
            $table->string('titre')->nullable()->after('type_notification');
        });
    }

    public function down(): void
    {
        Schema::table('notifications_ordispace', function (Blueprint $table) {
            $table->dropColumn('titre');
        });
    }
};
