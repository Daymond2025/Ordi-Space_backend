<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            // Garde-fou anti-double-crédit — même rôle que parrainage_recompense_versee.
            $table->boolean('commissions_fournisseurs_versees')->default(false)->after('livraison_gratuite_appliquee');
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('commissions_fournisseurs_versees');
        });
    }
};
