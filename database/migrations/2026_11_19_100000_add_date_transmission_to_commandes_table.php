<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commande programmée par un commercial (livraison à une date et une heure
 * choisies) : elle n'est transmise à la coordination, au fournisseur et aux
 * livreurs qu'une heure avant cette date. Null = transmise tout de suite.
 * Voir App\Models\Scopes\CommandeTransmiseScope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dateTime('date_transmission')->nullable()->after('date_commande')->index();
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropIndex(['date_transmission']);
            $table->dropColumn('date_transmission');
        });
    }
};
