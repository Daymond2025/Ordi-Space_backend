<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La connexion Client se fait désormais par téléphone (WhatsApp + OTP) :
     * le numéro doit être unique et dans un format stable (E.164), quel que
     * soit le format saisi par l'utilisateur ou déjà stocké en base.
     */
    public function up(): void
    {
        DB::table('users')->whereNotNull('telephone')->where('telephone', '!=', '')->orderBy('id')->lazy()->each(function ($utilisateur) {
            $normalise = normaliser_telephone($utilisateur->telephone);

            $collision = DB::table('users')->where('telephone', $normalise)->where('id', '!=', $utilisateur->id)->exists();

            if ($collision) {
                // Deux comptes partagent le même numéro une fois normalisé —
                // cas à traiter manuellement plutôt que de faire échouer tout
                // le déploiement ; on l'efface pour ne pas bloquer l'index unique.
                Log::warning("Téléphone en collision après normalisation, effacé pour l'utilisateur #{$utilisateur->id} : {$utilisateur->telephone}");
                DB::table('users')->where('id', $utilisateur->id)->update(['telephone' => null]);

                return;
            }

            DB::table('users')->where('id', $utilisateur->id)->update(['telephone' => $normalise]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('telephone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telephone']);
        });
    }
};
