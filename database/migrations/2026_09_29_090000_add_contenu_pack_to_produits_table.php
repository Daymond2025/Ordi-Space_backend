<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Pack complet" (fiche produit, onglet déjà présent dans
     * InfosProduit.tsx mais jusqu'ici toujours vide) — liste libre d'articles
     * accompagnant le produit (ex. "Sacoche", "Souris sans fil"), renseignée
     * par qui crée le produit. Même forme que `cadeaux` (JSON, tableau de
     * chaînes) mais un concept distinct : `cadeaux` provient d'une liste
     * prédéfinie (CADEAUX_DISPONIBLES, incitation marketing), `contenu_pack`
     * est du texte libre (ce qui est matériellement inclus dans le carton).
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->json('contenu_pack')->nullable()->after('cadeaux');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('contenu_pack');
        });
    }
};
