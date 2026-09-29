<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vente d'un produit faite par le fournisseur hors du flux commande
     * in-app (il encaisse lui-même le client, en espèces ou mobile money) —
     * voir la note sur STATUT_ACHAT_EXTERNE_* dans app/Helpers/const.php.
     */
    public function up(): void
    {
        Schema::create('achats_externes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->foreignId('fournisseur_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('montant_vente', 10, 2);
            // Snapshot du taux au moment de la déclaration : un changement de
            // taux_commission plus tard ne doit jamais réécrire l'historique.
            $table->decimal('taux_commission_applique', 5, 2);
            $table->decimal('commission_due', 10, 2);
            $table->string('statut', 20)->default('en_attente');
            $table->string('reference_paiement')->nullable();
            $table->date('date_vente');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['fournisseur_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achats_externes');
    }
};
