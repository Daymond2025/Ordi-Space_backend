<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutoriels', function (Blueprint $table) {
            $table->string('url_video')->nullable()->after('type');
            $table->text('contenu')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tutoriels', function (Blueprint $table) {
            $table->dropColumn('url_video');
            $table->text('contenu')->nullable(false)->change();
        });
    }
};
