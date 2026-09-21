<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiement de confirmation (Wave) d'une commande passée sur la page acheteur :
 * l'acheteur règle 200 F, jamais remboursés, pour prouver qu'il achètera vraiment ;
 * le reste se paie à la livraison. La commande n'est créée qu'une fois ce paiement
 * confirmé par le webhook Wave — jusque-là, seule cette ligne existe ("intention"),
 * avec de quoi créer la commande dans `donnees`. Une ligne payée sans commande
 * (`erreur` renseignée) est une anomalie à traiter par l'Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acomptes_confirmation', function (Blueprint $table) {
            $table->id();
            // Identifiant public (adresse de retour de Wave, suivi côté acheteur) : jamais l'id.
            $table->string('token', 40)->unique();
            $table->foreignId('commande_id')->nullable()->unique()->constrained('commandes')->nullOnDelete();
            $table->unsignedInteger('montant');
            $table->string('statut', 15)->default('en_attente')->index();
            $table->string('wave_checkout_session_id')->nullable()->index();
            $table->text('wave_launch_url')->nullable();
            $table->timestamp('date_paiement')->nullable();
            $table->json('donnees');
            $table->text('erreur')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acomptes_confirmation');
    }
};
