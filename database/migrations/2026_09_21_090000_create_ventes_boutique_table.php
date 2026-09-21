<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Boutique" — vente réalisée par un Livreur (écran "Centre des ventes").
     * Rattache une commande ordinaire au livreur qui l'a apportée et fige la
     * commission qu'il en tire au moment de la vente : `commission_revente`
     * d'un produit peut être modifiée plus tard par son créateur sans
     * rétroagir sur une vente déjà faite. Le statut affiché n'est pas stocké :
     * il découle toujours du statut de la commande (VenteBoutique::statutAffiche()).
     * Une commande = au plus une vente (commande_id unique).
     */
    public function up(): void
    {
        Schema::create('ventes_boutique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('livreur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('commande_id')->unique()->constrained('commandes')->cascadeOnDelete();
            $table->foreignId('lien_affilie_id')->nullable()->constrained('liens_affilies')->nullOnDelete();
            // Canal d'arrivée : commande saisie à la main, lien partagé
            // (WhatsApp) ou scan du QR de l'affiche — liste ouverte, voir
            // SOURCES_VENTE_BOUTIQUE.
            $table->string('source', 20);
            $table->decimal('commission', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['livreur_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventes_boutique');
    }
};
