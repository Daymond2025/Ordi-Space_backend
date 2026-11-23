<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alertes « Nouvelle commande à valider » envoyées avant que la validation
 * ne coupe la sonnerie chez les autres coordinateurs : celles des commandes
 * déjà traitées ne doivent plus sonner (elles restent non lues).
 */
return new class extends Migration
{
    public function up(): void
    {
        $traitees = DB::table('commandes')
            ->where('statut_commande', '!=', STATUT_COMMANDE_EN_ATTENTE)
            ->pluck('id')
            ->map(fn ($id) => "/commande/{$id}");

        foreach ($traitees->chunk(500) as $liens) {
            DB::table('notifications_ordispace')
                ->where('type_notification', 'commande_nouvelle')
                ->where('urgente', true)
                ->whereIn('lien', $liens->all())
                ->update(['urgente' => false]);
        }
    }

    public function down(): void
    {
        // Rien à restaurer : seule la répétition de la sonnerie est coupée.
    }
};
