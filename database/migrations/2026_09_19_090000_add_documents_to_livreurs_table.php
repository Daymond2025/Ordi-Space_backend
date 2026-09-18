<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Décision PDG : le livreur manipule l'argent du client (cash à la
     * livraison) — permis, CNI et carte grise sont exigés dès l'inscription
     * pour pouvoir l'identifier formellement en cas de vol/litige. La photo
     * de profil équivalente vit déjà sur users.photo (voir migration
     * add_photo_to_users_table).
     */
    public function up(): void
    {
        Schema::table('livreurs', function (Blueprint $table) {
            $table->string('photo_permis')->nullable()->after('type_vehicule');
            $table->string('photo_cni')->nullable()->after('photo_permis');
            $table->string('photo_carte_grise')->nullable()->after('photo_cni');
        });
    }

    public function down(): void
    {
        Schema::table('livreurs', function (Blueprint $table) {
            $table->dropColumn(['photo_permis', 'photo_cni', 'photo_carte_grise']);
        });
    }
};
