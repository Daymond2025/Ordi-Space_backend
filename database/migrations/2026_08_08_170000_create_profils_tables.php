<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables de spécialisation du MCD (CIF totale et exclusive sur UTILISATEUR).
     * Chaque table partage sa clé primaire avec users.id.
     */
    public function up(): void
    {
        Schema::create('fournisseurs', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('nom_entreprise');
            $table->string('adresse_entreprise')->nullable();
            $table->string('contact_pro')->nullable();
            $table->timestamps();
        });

        Schema::create('commerciaux', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->enum('type_commercial', ['humain', 'ia']);
            $table->string('matricule')->nullable();
            $table->string('nom_modele_ia')->nullable();
            $table->timestamps();
        });

        Schema::create('coordinateurs', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->date('date_inscription')->useCurrent();
            $table->timestamps();
        });

        Schema::create('livreurs', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('type_vehicule')->nullable();
            $table->string('zone_couverture')->nullable();
            $table->timestamps();
        });

        Schema::create('techniciens_maintenance', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('specialite')->nullable();
            $table->timestamps();
        });

        Schema::create('administrateurs', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('administrateurs');
        Schema::dropIfExists('techniciens_maintenance');
        Schema::dropIfExists('livreurs');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('coordinateurs');
        Schema::dropIfExists('commerciaux');
        Schema::dropIfExists('fournisseurs');
    }
};
