<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garanties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ligne_commande_id')->unique()->constrained('lignes_commande')->cascadeOnDelete();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->enum('type_garantie', ['constructeur', 'ordispace']);
            $table->text('conditions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garanties');
    }
};
