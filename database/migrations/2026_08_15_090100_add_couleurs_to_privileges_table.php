<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Couleurs de la carte affichée côté client (Privilège Space) : chaque
     * carte a un dégradé propre côté maquette, désormais choisi par l'Admin
     * à la création plutôt que codé en dur côté app.
     */
    public function up(): void
    {
        Schema::table('privileges', function (Blueprint $table) {
            $table->string('couleur_debut', 7)->default('#0077FF')->after('ordre_affichage');
            $table->string('couleur_fin', 7)->default('#00BFFF')->after('couleur_debut');
        });
    }

    public function down(): void
    {
        Schema::table('privileges', function (Blueprint $table) {
            $table->dropColumn(['couleur_debut', 'couleur_fin']);
        });
    }
};
