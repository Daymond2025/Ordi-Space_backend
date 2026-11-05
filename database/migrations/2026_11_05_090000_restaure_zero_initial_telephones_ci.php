<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * L'ancienne normaliser_telephone() retirait le 0 initial ("0564220887"
     * → "+225564220887") alors qu'il fait partie du numéro ivoirien à 10
     * chiffres : wa.me et l'envoi d'OTP WhatsApp visaient un numéro
     * inexistant. On le restaure ("+2250564220887").
     */
    public function up(): void
    {
        DB::table('users')->where('telephone', 'like', '+225%')->orderBy('id')->lazy()->each(function ($utilisateur) {
            if (! preg_match('/^\+225(\d{9})$/', $utilisateur->telephone, $m)) {
                return;
            }

            $corrige = '+2250'.$m[1];

            // Même numéro déjà enregistré au bon format pour ce rôle (compte en
            // double créé par la boutique publique) : à fusionner à la main.
            $collision = DB::table('users')->where('telephone', $corrige)
                ->where('type_utilisateur', $utilisateur->type_utilisateur)->exists();

            if ($collision) {
                Log::warning("Téléphone non corrigé (doublon) pour l'utilisateur #{$utilisateur->id}");

                return;
            }

            DB::table('users')->where('id', $utilisateur->id)->update(['telephone' => $corrige]);
        });
    }

    public function down(): void
    {
        // Irréversible : l'ancien format était erroné.
    }
};
