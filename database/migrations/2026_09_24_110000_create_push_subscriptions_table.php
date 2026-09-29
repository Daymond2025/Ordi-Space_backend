<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Abonnements Web Push (navigateur) — un utilisateur peut en avoir
     * plusieurs (plusieurs appareils/navigateurs). `endpoint` est l'URL du
     * service push du navigateur (longue, jusqu'à ~500+ caractères sur
     * certains — Firefox notamment) : on indexe `endpoint_hash` (sha256)
     * plutôt que l'endpoint brut, pour rester sous les limites d'index MySQL
     * en utf8mb4. Voir App\Services\PushNotificationService.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('p256dh');
            $table->string('auth');
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
