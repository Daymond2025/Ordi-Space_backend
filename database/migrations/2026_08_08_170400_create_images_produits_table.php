<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('images_produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->string('url_image');
            $table->unsignedSmallInteger('ordre_affichage')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images_produits');
    }
};
