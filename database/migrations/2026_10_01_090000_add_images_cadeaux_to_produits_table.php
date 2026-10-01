<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photo associée à chacun des `cadeaux` sélectionnés (ex. une vraie photo
     * de la souris offerte plutôt que juste le nom) — JSON associatif
     * {"Souris": "produits/cadeaux/xxx.jpg", ...}, un chemin relatif au disque
     * "public" par cadeau (même convention que ImageProduit::urlImage() :
     * jamais d'URL absolue en base). Voir Produit::imagesCadeaux() pour la
     * résolution en URL complète à la lecture, et
     * ProduitController::stockerImagesCadeaux() pour l'écriture.
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->json('images_cadeaux')->nullable()->after('contenu_pack');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('images_cadeaux');
        });
    }
};
