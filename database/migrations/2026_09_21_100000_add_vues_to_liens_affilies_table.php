<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Onglet "Vente par lien" du Centre des ventes : nombre de visites du
     * lien affilié et date de sa dernière activité (visite ou commande
     * passée par ce lien) — "il y a 2h" sur la carte du lien.
     */
    public function up(): void
    {
        Schema::table('liens_affilies', function (Blueprint $table) {
            $table->unsignedInteger('vues')->default(0)->after('code');
            $table->timestamp('derniere_activite_le')->nullable()->after('vues');
        });
    }

    public function down(): void
    {
        Schema::table('liens_affilies', function (Blueprint $table) {
            $table->dropColumn(['vues', 'derniere_activite_le']);
        });
    }
};
