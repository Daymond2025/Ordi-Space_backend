<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Charge utile structurée ({statut_apres, livreur_id, ...}) pour les
     * entrées liées à un changement de statut/assignation de commande — le
     * front en a besoin pour choisir icône/couleur de la timeline de suivi
     * sans parser le texte français de `details`. Les entrées existantes
     * restent `null` (repli sur une icône générique côté front).
     */
    public function up(): void
    {
        Schema::table('journal_audit', function (Blueprint $table) {
            $table->json('donnees')->nullable()->after('details');
        });
    }

    public function down(): void
    {
        Schema::table('journal_audit', function (Blueprint $table) {
            $table->dropColumn('donnees');
        });
    }
};
