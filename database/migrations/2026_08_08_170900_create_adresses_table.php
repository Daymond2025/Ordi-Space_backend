<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients', 'user_id')->cascadeOnDelete();
            $table->string('libelle')->nullable();
            $table->string('rue');
            $table->string('ville');
            $table->string('pays');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adresses');
    }
};
