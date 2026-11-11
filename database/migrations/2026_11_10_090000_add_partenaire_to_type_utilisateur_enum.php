<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nouveau rôle "partenaire" : représentant d'Ordi'Space dans une ou
 * plusieurs villes (voir App\Models\Partenaire).
 */
return new class extends Migration
{
    private const ROLES = ['fournisseur', 'commercial', 'coordinateur', 'client', 'livreur', 'technicien_maintenance', 'administrateur'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('type_utilisateur', [...self::ROLES, 'partenaire'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('type_utilisateur', self::ROLES)->change();
        });
    }
};
