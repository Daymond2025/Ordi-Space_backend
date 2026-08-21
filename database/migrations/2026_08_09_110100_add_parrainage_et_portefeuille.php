<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parrainage ("Carte invitation") : chaque client a un code personnel
     * (ACHAT-XX-2026) ; le filleul le fournit à sa commande, le parrain est
     * crédité sur son portefeuille à la livraison d'un véritable ordinateur.
     *
     * Livraison gratuite ("Carte free") : détectée automatiquement à la 2e
     * commande d'un client, pas de code — juste un repère informatif.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('code_parrainage')->unique()->nullable()->after('date_inscription');
            $table->decimal('solde_portefeuille', 12, 2)->default(0)->after('code_parrainage');
        });

        // Les cartes automatiques (livraison gratuite, parrainage) n'ont pas
        // de code catalogue unique à saisir — code_promo devient optionnel.
        // Syntaxe Schema Builder portable (fonctionne aussi sur SQLite en
        // tests), plutôt qu'un ALTER TABLE ... MODIFY spécifique à MySQL.
        Schema::table('privileges', function (Blueprint $table) {
            $table->string('code_promo', 50)->nullable()->change();
        });

        Schema::table('commandes', function (Blueprint $table) {
            $table->foreignId('parrain_id')->nullable()->after('privilege_id')
                ->constrained('clients', 'user_id')->nullOnDelete();
            $table->boolean('parrainage_recompense_versee')->default(false)->after('parrain_id');
            $table->boolean('livraison_gratuite_appliquee')->default(false)->after('parrainage_recompense_versee');
        });

        // Rétrocompatibilité : les clients déjà créés avant cette fonctionnalité
        // reçoivent aussi un code de parrainage.
        $clients = DB::table('clients')
            ->join('users', 'users.id', '=', 'clients.user_id')
            ->whereNull('clients.code_parrainage')
            ->select('clients.user_id', 'users.nom')
            ->get();

        foreach ($clients as $client) {
            $initiales = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $client->nom) ?: 'XX', 0, 2));
            $base = "ACHAT-{$initiales}-2026";
            $code = $base;
            $suffixe = 1;

            while (DB::table('clients')->where('code_parrainage', $code)->exists()) {
                $code = "{$base}-{$suffixe}";
                $suffixe++;
            }

            DB::table('clients')->where('user_id', $client->user_id)->update(['code_parrainage' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parrain_id');
            $table->dropColumn(['parrainage_recompense_versee', 'livraison_gratuite_appliquee']);
        });

        Schema::table('privileges', function (Blueprint $table) {
            $table->string('code_promo', 50)->nullable(false)->change();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['code_parrainage', 'solde_portefeuille']);
        });
    }
};
