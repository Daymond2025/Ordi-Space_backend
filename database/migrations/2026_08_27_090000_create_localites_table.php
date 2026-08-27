<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('localites', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->enum('type', ['commune_abidjan', 'ville']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('localites');
    }
};
