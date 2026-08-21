<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->foreignId('privilege_id')->nullable()->after('canal_vente_id')
                ->constrained('privileges')->nullOnDelete();
            $table->decimal('montant_remise', 12, 2)->default(0)->after('montant_total');
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('privilege_id');
            $table->dropColumn('montant_remise');
        });
    }
};
