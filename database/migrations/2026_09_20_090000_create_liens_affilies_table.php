<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Boutique" — lien affilié qu'un Livreur partage pour revendre un
     * produit publié par Fournisseur/Coordinateur/Admin (voir
     * BoutiqueController::genererLien()). Un seul lien par (produit, livreur) :
     * re-cliquer sur "Vendre ce produit" renvoie toujours le même code.
     */
    public function up(): void
    {
        Schema::create('liens_affilies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->foreignId('livreur_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->timestamps();

            $table->unique(['produit_id', 'livreur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liens_affilies');
    }
};
